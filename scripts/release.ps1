#Requires -Version 7.0
<#
.SYNOPSIS
    Release Newsstand: (optionally) bump the version, build and push the Docker image,
    tag the release in git and publish it on GitHub.

.DESCRIPTION
    Run it from the development branch. Steps, in this order (nothing public happens until
    the image has built):
      1. Preflight: git, docker and gh available and logged in, clean working tree,
         branches up to date with origin, and development merges into main without conflicts.
      2. Merge development into main (a merge commit), unless -NoMerge or you start on main.
      3. Version: VERSION as-is, or bumped with -Bump / set with -Version. With a bump,
         the CHANGELOG's [Unreleased] notes move into a new dated section.
         The compare links at the bottom of the CHANGELOG are kept up to date.
      4. Tests on the merged main (local SQLite, never against your real database), unless -SkipTests.
      5. Commit VERSION/CHANGELOG (only if they changed) and create the annotated tag vX.Y.Z.
      6. docker build (linux/amd64) tagged X.Y.Z, X.Y, X and latest (prereleases: X.Y.Z only).
      7. docker push; merge main back into development (so it has the version commit);
         git push main, the tag and development; then a GitHub release whose notes are the
         CHANGELOG section for this version. You end up back on development.

    If something fails before the first push, the local changes (merge, version commit, tag)
    are undone and you're put back on the branch you started on.

    Use -DryRun first: it shows what would happen and changes nothing.

.EXAMPLE
    ./scripts/release.ps1 -DryRun
    From development: show how it would merge into main and release the version in VERSION
    (must not be tagged yet). Changes nothing.

.EXAMPLE
    ./scripts/release.ps1 -Bump patch
    1.4.0 -> 1.4.1, moving the [Unreleased] changelog notes into the 1.4.1 section.

.EXAMPLE
    ./scripts/release.ps1 -NoMerge
    From main: release main as it is, without merging development in or back.

.EXAMPLE
    ./scripts/release.ps1 -Version 2.0.0-rc.1
    A prerelease: only the exact Docker tag is pushed and the GitHub release is marked prerelease.
#>
[CmdletBinding()]
param(
    # Bump the version in VERSION before releasing.
    [ValidateSet('none', 'patch', 'minor', 'major')]
    [string] $Bump = 'none',

    # Release this exact version instead (SemVer, e.g. 1.5.0 or 1.5.0-rc.1). Overrides -Bump.
    [string] $Version,

    # Branch releases are made from (and tagged on).
    [string] $Branch = 'main',

    # Branch that is merged into -Branch first, and gets the version commit merged back.
    [string] $From = 'development',

    # Release -Branch as it is: no merge from -From, no merge back.
    [switch] $NoMerge,

    # Docker Hub repository.
    [string] $Image = 'burnacid/newsstand',

    [string] $Platform = 'linux/amd64',

    # Don't move the "latest" Docker tag (e.g. when releasing a fix for an older line).
    [switch] $NoLatest,

    [switch] $SkipTests,

    # Create the GitHub release as a draft.
    [switch] $Draft,

    # Show what would happen without changing anything.
    [switch] $DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

# --- Helpers -----------------------------------------------------------------------------

function Write-Step([string] $text) { Write-Host "`n==> $text" -ForegroundColor Cyan }
function Write-Info([string] $text) { Write-Host "    $text" -ForegroundColor DarkGray }

# What has been done locally, so a failure before anything is pushed can be undone.
$state = @{
    StartBranch = $null   # branch the script was started on
    BranchStart = $null   # commit -Branch pointed at before the merge / version commit
    OnBranch = $false     # whether we switched to (or started on) -Branch and may have changed it
    Tag = $null           # local tag created
    Public = $false       # something was pushed: no more automatic undo
}

function Undo-LocalRelease {
    if ($DryRun -or $state.Public) { return }

    if ($state.Tag) { & git tag -d $state.Tag *> $null }

    if ($state.OnBranch -and $state.BranchStart) {
        & git merge --abort *> $null
        & git checkout -q -f $Branch *> $null
        & git reset -q --hard $state.BranchStart *> $null
        Write-Host "    Undid local changes on '$Branch'." -ForegroundColor Yellow
    }

    if ($state.StartBranch -and (& git rev-parse --abbrev-ref HEAD) -ne $state.StartBranch) {
        & git checkout -q $state.StartBranch *> $null
        Write-Host "    Back on '$($state.StartBranch)'." -ForegroundColor Yellow
    }
}

function Stop-Release([string] $text) {
    Write-Host "`nERROR: $text" -ForegroundColor Red

    if ($state.Public) {
        Write-Host '    Something was already pushed, so nothing was undone. Fix the problem and finish the remaining steps by hand.' -ForegroundColor Yellow
    } else {
        Undo-LocalRelease
    }

    exit 1
}

# Run a native command; fail on a non-zero exit code. In -DryRun, only print mutating commands.
function Invoke-Native {
    param([string] $Exe, [string[]] $Arguments, [switch] $Mutates, [switch] $Quiet)

    $line = "$Exe $($Arguments -join ' ')"

    if ($Mutates -and $DryRun) {
        Write-Host "    [dry-run] $line" -ForegroundColor Yellow
        return
    }

    if (-not $Quiet) { Write-Info $line }
    & $Exe @Arguments
    if ($LASTEXITCODE -ne 0) { Stop-Release "'$line' failed (exit code $LASTEXITCODE)." }
}

# Run a native command and return its trimmed output (read-only commands).
function Get-NativeOutput([string] $Exe, [string[]] $Arguments) {
    $out = & $Exe @Arguments 2>&1
    return @{ Code = $LASTEXITCODE; Text = (($out | Out-String).Trim()) }
}

function Get-NextVersion([string] $current, [string] $part) {
    if ($current -notmatch '^(\d+)\.(\d+)\.(\d+)') { Stop-Release "VERSION '$current' is not SemVer." }
    $major = [int] $Matches[1]; $minor = [int] $Matches[2]; $patch = [int] $Matches[3]

    switch ($part) {
        'major' { return "$($major + 1).0.0" }
        'minor' { return "$major.$($minor + 1).0" }
        'patch' { return "$major.$minor.$($patch + 1)" }
        default { return $current }
    }
}

# --- 1. Preflight ------------------------------------------------------------------------

Write-Step 'Preflight'

foreach ($tool in 'git', 'docker', 'gh', 'php') {
    if (-not (Get-Command $tool -ErrorAction SilentlyContinue)) { Stop-Release "'$tool' is not installed or not on PATH." }
}

if ((Get-NativeOutput docker @('info', '--format', '{{.ServerVersion}}')).Code -ne 0) {
    Stop-Release 'Docker is not running. Start Docker Desktop and try again.'
}

if ((Get-NativeOutput gh @('auth', 'status')).Code -ne 0) {
    Stop-Release "GitHub CLI is not logged in. Run 'gh auth login'."
}

$status = (Get-NativeOutput git @('status', '--porcelain')).Text
if ($status) { Stop-Release "The working tree has uncommitted changes:`n$status`nCommit or stash them first." }

$current = (Get-NativeOutput git @('rev-parse', '--abbrev-ref', 'HEAD')).Text
$state.StartBranch = $current

# Merge From into Branch when started on From; started on Branch, release it as it is.
$merge = -not $NoMerge -and $current -eq $From
$mergeBack = -not $NoMerge -and (Get-NativeOutput git @('rev-parse', '--verify', '--quiet', "refs/heads/$From")).Code -eq 0

if ($current -ne $Branch -and -not $merge) {
    Stop-Release "You are on '$current'. Start on '$From' (it gets merged into '$Branch') or on '$Branch'."
}

Invoke-Native git @('fetch', '--tags', '--quiet', 'origin') -Quiet

# A fresh clone may only have origin/main: create the local branch from it.
if ((Get-NativeOutput git @('rev-parse', '--verify', '--quiet', "refs/heads/$Branch")).Code -ne 0) {
    if ((Get-NativeOutput git @('rev-parse', '--verify', '--quiet', "refs/remotes/origin/$Branch")).Code -ne 0) {
        Stop-Release "There is no '$Branch' branch, locally or on origin."
    }
    # Harmless (just a local tracking branch), so done in -DryRun too.
    Write-Info "Creating local '$Branch' from origin/$Branch."
    Invoke-Native git @('branch', '--quiet', '--track', $Branch, "origin/$Branch") -Quiet
}

function Get-Count([string] $range) { [int] (Get-NativeOutput git @('rev-list', '--count', $range)).Text }

foreach ($b in @($Branch) + ($mergeBack ? @($From) : @())) {
    if ((Get-NativeOutput git @('rev-parse', '--verify', '--quiet', "refs/remotes/origin/$b")).Code -ne 0) { continue }

    $behind = Get-Count "$b..origin/$b"
    $ahead = Get-Count "origin/$b..$b"

    if ($behind -gt 0 -and $ahead -gt 0) { Stop-Release "'$b' and origin/$b have diverged. Sort that out first." }

    if ($behind -gt 0) {
        if ($b -eq $current) { Stop-Release "'$b' is $behind commit(s) behind origin/$b. Pull first." }
        # Not checked out: fast-forward it in place.
        Write-Info "'$b' is $behind commit(s) behind origin/${b}: fast-forwarding."
        Invoke-Native git @('fetch', '--quiet', 'origin', "${b}:${b}") -Mutates -Quiet
    }
}

if ($merge) {
    $toMerge = Get-Count "$Branch..$From"

    if ($toMerge -eq 0) {
        Write-Info "'$From' has nothing that isn't in '$Branch' yet."
    } else {
        # Dry merge: fails when there would be conflicts.
        $probe = Get-NativeOutput git @('merge-tree', '--write-tree', '--name-only', $Branch, $From)
        if ($probe.Code -ne 0) {
            Stop-Release "Merging '$From' into '$Branch' has conflicts:`n$($probe.Text)`nMerge by hand first."
        }
        Write-Info "'$From' merges cleanly into '$Branch' ($toMerge commit(s))."
    }
}

Write-Info "git, docker and gh are ready; clean and up to date."

# --- 2. Merge ------------------------------------------------------------------------------

if ($merge) {
    Write-Step "Merge '$From' into '$Branch'"

    if (-not $DryRun) {
        $state.BranchStart = (Get-NativeOutput git @('rev-parse', $Branch)).Text
        $state.OnBranch = $true
    }

    Invoke-Native git @('checkout', '--quiet', $Branch) -Mutates

    if ($toMerge -gt 0) {
        Invoke-Native git @('merge', '--no-ff', '--quiet', '-m', "Merge branch '$From' into $Branch", $From) -Mutates
    }
} else {
    $state.BranchStart = (Get-NativeOutput git @('rev-parse', 'HEAD')).Text
    $state.OnBranch = $true
}

# --- 3. Version and changelog ------------------------------------------------------------

Write-Step 'Version'

$versionFile = Join-Path $root 'VERSION'
$changelogFile = Join-Path $root 'CHANGELOG.md'
$oldVersion = (Get-Content $versionFile -Raw).Trim()

$newVersion = if ($Version) { $Version.TrimStart('v') } else { Get-NextVersion $oldVersion $Bump }

if ($newVersion -notmatch '^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$') { Stop-Release "'$newVersion' is not a valid SemVer version." }

$tag = "v$newVersion"
$prerelease = $newVersion.Contains('-')
$bumping = $newVersion -ne $oldVersion

if ((Get-NativeOutput git @('tag', '--list', $tag)).Text) {
    Stop-Release "Tag $tag already exists. Use -Bump patch|minor|major or -Version to release a new version."
}
if ((Get-NativeOutput git @('ls-remote', '--tags', 'origin', "refs/tags/$tag")).Text) {
    Stop-Release "Tag $tag already exists on GitHub."
}

Write-Info ($bumping ? "Bumping $oldVersion -> $newVersion" : "Releasing $newVersion (from VERSION)")

$changelog = Get-Content $changelogFile -Raw
$nl = $changelog.Contains("`r`n") ? "`r`n" : "`n"
$changelog = $changelog -replace "`r`n", "`n"
$escaped = [regex]::Escape($newVersion)

if ($bumping) {
    # Move the [Unreleased] notes into a new section for this version.
    $match = [regex]::Match($changelog, '(?s)## \[Unreleased\]\n(.*?)(?=\n## \[|\n\[Unreleased\]:|\z)')
    if (-not $match.Success) { Stop-Release 'CHANGELOG.md has no "## [Unreleased]" section.' }

    $notes = $match.Groups[1].Value.Trim()
    if (-not $notes) { Stop-Release 'The [Unreleased] section of CHANGELOG.md is empty. Describe the changes first.' }
    if ($changelog -match "## \[$escaped\]") { Stop-Release "CHANGELOG.md already has a section for $newVersion." }

    $date = Get-Date -Format 'yyyy-MM-dd'
    $section = "## [Unreleased]`n`n## [$newVersion] - $date`n`n$notes`n"
    $changelog = $changelog.Substring(0, $match.Index) + $section + $changelog.Substring($match.Index + $match.Length)
} elseif ($changelog -notmatch "## \[$escaped\]") {
    Stop-Release "CHANGELOG.md has no section for $newVersion. Add one (or use -Bump to create it from [Unreleased])."
}

# The version before this one = the next section heading below it.
$headings = [regex]::Matches($changelog, '(?m)^## \[(\d+\.\d+\.\d+[^\]]*)\]') | ForEach-Object { $_.Groups[1].Value }
$index = [array]::IndexOf(@($headings), $newVersion)
$previous = ($index -ge 0 -and $index + 1 -lt @($headings).Count) ? @($headings)[$index + 1] : $null

# Compare links at the bottom.
$repoUrl = 'https://github.com/' + (Get-NativeOutput gh @('repo', 'view', '--json', 'nameWithOwner', '--jq', '.nameWithOwner')).Text
$unreleasedLink = "[Unreleased]: $repoUrl/compare/$tag...HEAD"
$versionLink = $previous ? "[$newVersion]: $repoUrl/compare/v$previous...$tag" : "[$newVersion]: $repoUrl/releases/tag/$tag"

if ($changelog -match '(?m)^\[Unreleased\]:.*$') {
    $changelog = $changelog -replace '(?m)^\[Unreleased\]:.*$', $unreleasedLink
} else {
    $changelog = $changelog.TrimEnd() + "`n`n$unreleasedLink`n"
}
if ($changelog -notmatch "(?m)^\[$escaped\]:") {
    $changelog = $changelog -replace '(?m)^(\[Unreleased\]:.*)$', "`$1`n$versionLink"
}

$changelog = $changelog -replace "`n", $nl

# Release notes = this version's section.
$notesMatch = [regex]::Match(($changelog -replace "`r`n", "`n"), "(?s)## \[$escaped\][^\n]*\n(.*?)(?=\n## \[|\n\[Unreleased\]:|\z)")
$releaseNotes = $notesMatch.Groups[1].Value.Trim()

$filesChanged = $bumping -or ($changelog -ne (Get-Content $changelogFile -Raw))

if (-not $filesChanged) {
    Write-Info 'VERSION and CHANGELOG.md are already up to date.'
} elseif ($DryRun) {
    Write-Host "    [dry-run] would write VERSION = $newVersion and update CHANGELOG.md ($versionLink)" -ForegroundColor Yellow
} else {
    Set-Content -Path $versionFile -Value $newVersion -NoNewline:$false -Encoding utf8NoBOM
    [IO.File]::WriteAllText($changelogFile, $changelog, [Text.UTF8Encoding]::new($false))
    Write-Info 'Updated VERSION and CHANGELOG.md.'
}

# --- 4. Tests ----------------------------------------------------------------------------

if ($SkipTests) {
    Write-Step 'Tests skipped (-SkipTests)'
} else {
    Write-Step 'Tests'
    # A cached config would point the tests at the real database: clear it first.
    Invoke-Native php @('artisan', 'config:clear', '--quiet')
    Invoke-Native php @('artisan', 'test', '--compact')
}

# --- 5. Commit and tag (local) -----------------------------------------------------------

Write-Step "Commit and tag $tag"

if ($filesChanged) {
    Invoke-Native git @('add', 'VERSION', 'CHANGELOG.md') -Mutates
    Invoke-Native git @('commit', '--quiet', '-m', "Release $tag") -Mutates
} else {
    Write-Info 'VERSION and CHANGELOG.md already up to date: nothing to commit.'
}
Invoke-Native git @('tag', '-a', $tag, '-m', "Newsstand $newVersion") -Mutates
if (-not $DryRun) { $state.Tag = $tag }

# --- 6. Docker build ---------------------------------------------------------------------

Write-Step "Docker build ($Platform)"

$dockerTags = @($newVersion)
if (-not $prerelease) {
    $parts = $newVersion.Split('.')
    $dockerTags += "$($parts[0]).$($parts[1])", $parts[0]
    if (-not $NoLatest) { $dockerTags += 'latest' }
}

$buildArgs = @('build', '--pull', '--platform', $Platform, '--build-arg', "VERSION=$newVersion")
foreach ($t in $dockerTags) { $buildArgs += '-t', "${Image}:$t" }
$buildArgs += '.'

Invoke-Native docker $buildArgs -Mutates

if (-not $DryRun) {
    $label = (Get-NativeOutput docker @('image', 'inspect', "${Image}:$newVersion", '--format', '{{ index .Config.Labels "org.opencontainers.image.version" }}')).Text
    if ($label -ne $newVersion) { Stop-Release "The built image reports version '$label', expected '$newVersion'." }
    Write-Info "Built ${Image}: $($dockerTags -join ', ')"
}

# --- 7. Publish ----------------------------------------------------------------------------

Write-Step 'Push Docker image'
if (-not $DryRun) { $state.Public = $true }
foreach ($t in $dockerTags) { Invoke-Native docker @('push', "${Image}:$t") -Mutates }

if ($mergeBack) {
    Write-Step "Merge '$Branch' back into '$From'"
    Invoke-Native git @('checkout', '--quiet', $From) -Mutates

    # Usually a fast-forward (development only lacks the version commit and the merge).
    if (-not $DryRun -and (Get-NativeOutput git @('merge', '--ff-only', '--quiet', $Branch)).Code -ne 0) {
        if ((Get-NativeOutput git @('merge', '--no-edit', '--quiet', $Branch)).Code -ne 0) {
            & git merge --abort *> $null
            Write-Host "    '$Branch' doesn't merge cleanly into '$From': merge it by hand later. Continuing." -ForegroundColor Yellow
            $mergeBack = $false
            & git checkout -q $Branch
        }
    } elseif ($DryRun) {
        Write-Host "    [dry-run] git merge --ff-only $Branch" -ForegroundColor Yellow
    }
}

Write-Step 'Push to GitHub'
Invoke-Native git @('push', 'origin', $Branch) -Mutates
Invoke-Native git @('push', 'origin', $tag) -Mutates
if ($mergeBack) { Invoke-Native git @('push', 'origin', $From) -Mutates }

Write-Step 'GitHub release'

$body = @"
$releaseNotes

### Docker

``````
docker pull ${Image}:$newVersion
``````

Tags: $(($dockerTags | ForEach-Object { "``$_``" }) -join ', ')
"@

$notesFile = Join-Path ([IO.Path]::GetTempPath()) "newsstand-release-$newVersion.md"
[IO.File]::WriteAllText($notesFile, $body, [Text.UTF8Encoding]::new($false))

$releaseArgs = @('release', 'create', $tag, '--title', "Newsstand $newVersion", '--notes-file', $notesFile, '--verify-tag')
if ($prerelease) { $releaseArgs += '--prerelease' }
if ($Draft) { $releaseArgs += '--draft' }
if ($NoLatest -or $prerelease) { $releaseArgs += '--latest=false' }

if ($DryRun) {
    Write-Host "    [dry-run] gh $($releaseArgs -join ' ')" -ForegroundColor Yellow
    Write-Host "`n--- Release notes ---`n$body`n---------------------" -ForegroundColor DarkGray
} else {
    Invoke-Native gh $releaseArgs
}
Remove-Item $notesFile -ErrorAction SilentlyContinue

Write-Host "`nDone: Newsstand $newVersion" -ForegroundColor Green
if (-not $DryRun) {
    Write-Host "  Docker:  https://hub.docker.com/r/$Image/tags"
    Write-Host "  GitHub:  $repoUrl/releases/tag/$tag"
    Write-Host "  Now on:  $((Get-NativeOutput git @('rev-parse', '--abbrev-ref', 'HEAD')).Text)"
    if (-not $mergeBack -and -not $NoMerge) {
        Write-Host "  Remember to merge '$Branch' into '$From' so it has the version commit." -ForegroundColor DarkGray
    }
}
