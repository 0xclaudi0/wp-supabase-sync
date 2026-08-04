# WordPress.org directory assets

These files are **not part of the plugin**. They are the icon, banner and
screenshots that WordPress.org shows on the plugin's directory listing page.

## Why they live here and not in `assets/`

The plugin already has an `assets/` directory, and it means something different:
it holds `admin.css` and `admin.js`, which are enqueued at runtime via
`plugin_dir_url()` and ship inside the plugin ZIP.

On WordPress.org, directory assets live in a folder called `assets/` at the **SVN
repository root** — a sibling of `trunk/`, not inside it. Putting a banner into
the plugin's own `assets/` would ship a 1544×500 PNG to every user and do nothing
on the directory page.

Since this project is developed in Git, the convention is to keep them in
`.wordpress-org/` and let the deploy step copy them to SVN `assets/`. That is the
default directory name used by `10up/action-wordpress-plugin-deploy`, so it works
without configuration.

```
SVN repo root
├── assets/          <- what this directory becomes
├── trunk/           <- the plugin itself, including its own assets/
└── tags/0.1.0/
```

## Required files and exact dimensions

Dimensions must be **exact**. WordPress.org does not resize; an off-size image is
letterboxed, stretched, or ignored.

| File | Dimensions | Required |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | yes |
| `icon-256x256.png` | 256 × 256 | yes (retina) |
| `icon.svg` | square | optional, preferred — scales perfectly |
| `banner-772x250.png` | 772 × 250 | yes |
| `banner-1544x500.png` | 1544 × 500 | yes (retina) |
| `screenshot-1.png` … `screenshot-4.png` | any, keep consistent | yes |

`.jpg` is accepted for all of the above. Use PNG for the icon and banner here:
both have flat colour and sharp edges, which JPEG artefacts around.

Verify before committing:

```bash
cd .wordpress-org
for f in *.png; do printf '%-28s %s\n' "$f" "$(magick identify -format '%wx%h' "$f" 2>/dev/null || sips -g pixelWidth -g pixelHeight "$f" | awk 'NR>1{printf "%s ", $2}')"; done
```

## Screenshots

The order must match the `== Screenshots ==` list in `readme.txt`, which
currently describes four:

1. Settings, with the connection test and the "key is stored in the database"
   warning.
2. Diagnostics: twelve checks in dependency order.
3. A failing check with copy-pasteable SQL, distinguishing a missing GRANT from a
   row level security policy.
4. Logs and queue state, with credentials redacted.

Screenshot 3 is the one worth getting right — it is the whole argument for the
plugin in a single image. Produce it by dropping the grant on a live stack:

```sql
revoke all on public.wp_content from service_role;
```

then loading **Tools → Supabase Sync**. Restore it afterwards with the block from
`wp supabase schema --print`.

## Trademark note

Both the icon and the banner currently use the WordPress mark, and the banner
headline echoes Supabase's own tagline. That is a deliberate choice, but it is the
most likely thing to draw a change request from the plugin review team, since
combining two third-party marks reads as a claim of official affiliation. If that
happens, `CONTEXT.md` → "Next steps" has the fallback: a mark-free sync glyph and
a headline that does not borrow anyone's copy.
