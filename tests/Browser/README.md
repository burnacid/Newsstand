# Browser checks

Playwright scripts that drive the real UI against a running dev server and fail on
JavaScript errors. They catch problems the PHP tests can't, like a page whose Alpine/Livewire
initialization breaks and leaves buttons dead.

They log in as a throwaway user. Create it once (it never touches your own data):

```bash
php artisan tinker --execute='$u = App\Models\User::updateOrCreate(["email" => "playwright@example.test"], ["name" => "Playwright", "password" => bcrypt("playwright-pass")]); $u->forceFill(["email_verified_at" => now()])->save();'
```

Then, with `php artisan serve` running:

```bash
node tests/Browser/page-errors.mjs        # every page + reader modes: no JS errors
node tests/Browser/feeds-page-check.mjs   # delete/rename tag, unsubscribe (needs tags pw-delete-me, pw-rename-me and one subscription)
node tests/Browser/river-check.mjs        # river marks scrolled-past stories read
node tests/Browser/teams-check.mjs        # create a team, folder, invite link, team watchlist, a note; deletes it all again
node tests/Browser/dashboard-sort-check.mjs  # team dashboard in the sidebar, drag widgets to reorder; deletes it all again
```

They use the installed Microsoft Edge. To use Playwright's own Chromium instead:
`npx playwright install chromium` and set `PW_CHANNEL=chromium`.
