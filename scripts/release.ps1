#Requires -Version 7.0
<#
.SYNOPSIS
    Release Newsstand: (optionally) bump the version, build and push the Docker image,
    tag the release in git and publish it on GitHub.

.DESCRIPTION
    Steps, in this order (nothing public happens until the image has built):
      1. Preflight: git, docker and gh available and logged in, clean working tree,
         on the release branch and up to date with origin.
      2. Version: VERSION as-is, or bumped with -Bump / set with -Version. With a bump,
         the CHANGELOG's [Unreleased] notes move into a new dated section.
         The compare links at the bottom of the CHANGELOG are kept up to date.
      3. Tests (local SQLite, never against your real database), unless -SkipTests.
      4. Commit VERSION/CHANGELOG (only if they changed) and create the annotated tag vX.Y.Z.
      5. docker build (linux/amd64) tagged X.Y.Z, X.Y, X and latest (prereleases: X.Y.Z only).
      6. docker push, then git push (branch + tag), then a GitHub release whose notes are
         the CHANGELOG section for this version.

    Use -DryRun first: it shows what would happen and changes nothing.

.EXAMPLE
    ./scripts/release.ps1 -DryRun
    Release the version in VERSION (must not be tagged yet) and show what would happen.

.EXAMPLE
    ./scripts/release.ps1 -Bump patch
    1.4.0 -> 1.4.1, moving the [Unreleased] changelog notes into the 1.4.1 section.

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

    # Branch releases are made from.
    [string] $Branch = 'main',

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
function Stop-Release([string] $text) { Write-Host "`nERROR: $text" -ForegroundColor Red; exit 1 }

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
if ($current -ne $Branch) {
    Stop-Release "You are on '$current'. Releases are made from '$Branch' (merge development into it first, or pass -Branch $current)."
}

Invoke-Native git @('fetch', '--tags', '--quiet', 'origin') -Quiet
$behind = (Get-NativeOutput git @('rev-list', '--count', "HEAD..origin/$Branch")).Text
if ($behind -and [int] $behind -gt 0) { Stop-Release "'$Branch' is $behind commit(s) behind origin/$Branch. Pull first." }

Write-Info "git, docker and gh are ready; on '$Branch', clean and up to date."

# --- 2. Version and changelog ------------------------------------------------------------

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

if ($DryRun) {
    Write-Host "    [dry-run] would write VERSION = $newVersion and update CHANGELOG.md ($versionLink)" -ForegroundColor Yellow
} elseif ($filesChanged) {
    Set-Content -Path $versionFile -Value $newVersion -NoNewline:$false -Encoding utf8NoBOM
    [IO.File]::WriteAllText($changelogFile, $changelog, [Text.UTF8Encoding]::new($false))
    Write-Info 'Updated VERSION and CHANGELOG.md.'
}

# --- 3. Tests ----------------------------------------------------------------------------

if ($SkipTests) {
    Write-Step 'Tests skipped (-SkipTests)'
} else {
    Write-Step 'Tests'
    # A cached config would point the tests at the real database: clear it first.
    Invoke-Native php @('artisan', 'config:clear', '--quiet')
    Invoke-Native php @('artisan', 'test', '--compact')
}

# --- 4. Commit and tag (local) -----------------------------------------------------------

Write-Step "Commit and tag $tag"

if ($filesChanged) {
    Invoke-Native git @('add', 'VERSION', 'CHANGELOG.md') -Mutates
    Invoke-Native git @('commit', '--quiet', '-m', "Release $tag") -Mutates
} else {
    Write-Info 'VERSION and CHANGELOG.md already up to date: nothing to commit.'
}
Invoke-Native git @('tag', '-a', $tag, '-m', "Newsstand $newVersion") -Mutates

# --- 5. Docker build ---------------------------------------------------------------------

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

# --- 6. Publish ----------------------------------------------------------------------------

Write-Step 'Push Docker image'
foreach ($t in $dockerTags) { Invoke-Native docker @('push', "${Image}:$t") -Mutates }

Write-Step 'Push to GitHub'
Invoke-Native git @('push', 'origin', $Branch) -Mutates
Invoke-Native git @('push', 'origin', $tag) -Mutates

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
    if ($Branch -ne 'development') {
        Write-Host "  Tip: merge '$Branch' back into 'development' so it has the version commit." -ForegroundColor DarkGray
    }
}
