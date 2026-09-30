# Reaction emoji assets

Vendored Microsoft Fluent Emoji SVGs (Flat style, 32x32), the same set BuddyNext
ships, so a reaction looks the same on every platform instead of depending on the
OS emoji font.

Source: [microsoft/fluentui-emoji](https://github.com/microsoft/fluentui-emoji),
MIT for the code, CC-BY 4.0 for the graphical assets.

| Slug | Fluent Emoji asset |
| --- | --- |
| `like`  | Thumbs up |
| `love`  | Red heart |
| `haha`  | Face with tears of joy |
| `wow`   | Astonished face |
| `sad`   | Loudly crying face |
| `angry` | Angry face |
| `fire`  | Fire (streak badge) |

The six reaction slugs match `ReactionService::TYPES`. Resolve a URL with
`TemplateHelpers::emoji_url( $slug )`; add a type by dropping `<slug>.svg` here.
