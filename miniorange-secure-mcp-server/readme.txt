=== Secure MCP Server for Claude, ChatGPT, Gemini and other AI providers ===
Contributors: cyberlord92
Tags: mcp, ai, mcp-server, chatgpt, claude
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.4.8
License: Expat
License URI: https://plugins.miniorange.com/mit-license

The #1 secure WordPress MCP server for connecting Claude, ChatGPT, & AI agents (MCP Clients) with 300+ tools for WooCommerce, Elementor, audit logs etc.

== Description ==

miniOrange Secure MCP Server is a complete WordPress MCP server plugin that turns your WordPress site into a fully working Model Context Protocol (MCP) server. Once installed, you can connect Claude to WordPress, connect ChatGPT to WordPress, or link any MCP-compatible AI in just 2 minutes.

Ask AI to write blog posts, edit Elementor and Kadence page layouts, manage custom post types and their custom fields, upload media, update WooCommerce products, manage Yoast SEO, moderate comments, handle contact form submissions, and much more, all through simple chat.

Unlike other WordPress MCP server plugins that give AI full admin access, we put you in complete control. Role-based AI permissions, Turn each tool on or off, self-hosted OAuth 2.1 login, and full activity logs are all included, with no restrictions.

Video: Connect Chagpt to WordPress Using Secure MCP Server 

[youtube https://www.youtube.com/watch?v=yFzNXnguK00]


== Core WordPress MCP Server Features ==
The most complete WordPress MCP server on the WordPress marketplace:

* **300+ MCP Tools Included:** Full coverage across content, commerce, users, forms, and SEO.
* **Secure MCP Server with OAuth 2.1:** Self-hosted authorization server where AI never sees your WordPress password.
* **Role-Based AI Access (NHI Registry):** Assign different MCP tools to each role so every AI client gets its own powers.
* **Tool Controls:** Turn any MCP tool on or off with one click. 
* **Works with Every AI:** Connect Claude, ChatGPT, Cursor, Gemini, Windsurf, and any MCP-compatible AI.
* **One-Click OAuth Connect:** Paste your MCP server URL and approve access, with no API keys to copy.
* **Full Activity Log:** Every AI action recorded and reviewable.
* **WordPress Abilities API Native:** Built on WordPress 6.9's official standard for a future-proof design.
* **Unlimited Usage:** No caps on MCP calls, connected AI clients, or users.
* **Role-Based Access:** Give each user or AI only the access their role allows.
* **Agent Reputation Scoring:** Rates each AI on past behavior to spot trusted or risky agents.
* **Individual Credentials for Users:** Every user connects with their own login, not a shared one.
* **Dynamic OAuth & API Key Support:** Connect using OAuth or an API key, whichever you prefer.
* **Audit Log:** See every AI action, including who did it and when.
* **Policy Forming:** Build your own rules for what AI is allowed to do.
* **Abilities Restriction:** Turn specific AI abilities on or off to limit what AI can touch.
* **Human-in-the-Loop Approvals:** Send risky actions to a real person to approve first.
* **Data Rules + DLP:** Hide emails, phone numbers, and secrets so AI never sees them.
* **Prompt-Injection Detection:** Catch hidden or harmful instructions before they reach the AI.
* **Behavioral Anomaly Detection:** Watches AI activity and flags anything unusual.
* **Rate Limits & Quotas:** Cap how many requests each AI can make to stop overuse.
* **Multisite Policy:** Apply the same AI rules across all your WordPress sites.
* **Pre-Built Policy Templates:** Ready-made rule sets so you can set up safe AI access fast.

== Quick Links ==
<a href="https://plugins.miniorange.com/mcp-server-ai-policy-enforcement-wordpress" target="_blank">Official Website</a> | <a href="https://plugins.miniorange.com/setup-chatgpt-to-wordpress-abilities-api-using-mcp" target="_blank">ChatGpt Setup Guide</a> | <a href="https://plugins.miniorange.com/connect-wordpress-with-claude-mcp-guide" target="_blank">Claude Setup Guide

== What Is a WordPress MCP Server? ==

A WordPress MCP server is a plugin that lets AI tools like Claude by Anthropic and ChatGPT by OpenAI connect to your WordPress site through the Model Context Protocol (MCP), an open standard AI clients use to talk to external apps.

Think of MCP as USB-C for AI. One protocol. Every AI client. Every app. Install this WordPress MCP plugin, and your site instantly becomes an AI-ready backend at:

`https://YOUR-SITE/wp-json/mosmcp/v1/mcp`

Any AI that speaks MCP can now connect and manage your WordPress site through chat.

== WordPress Core Content Tools: 105 MCP Abilities ==

Full AI control over your WordPress content: posts, pages, media, categories, tags, and revisions.

* **Post Management (31 tools):** Find, create, update, publish, schedule, categorize, tag, trash, restore, or delete posts for full editorial automation. Edit the URL slug, set the featured image, choose the page template, and duplicate an existing post to reuse it as a template.
* **Page Management (25 tools):** Create drafts under parent pages, update any page you can edit, publish, schedule, make private, manage review, trash, restore, or delete pages. Edit slugs, set featured images, choose page templates, and duplicate a page with its layout and settings intact.
* **Media Library Management (19 tools):** Upload files straight into the library from a public URL or from supplied file contents, list or filter by type, get counts, find by title, update titles, alt text, captions, and descriptions, or delete files.
* **Category Management (11 tools):** List, create, rename, and update categories or slugs, find empty ones, and delete safely with reassignment to default.
* **Tag Management (10 tools):** List, create, rename, and update tags, find unused tags for cleanup, and delete safely without breaking posts.
* **Revisions & History (9 tools):** List, count, and retrieve revisions or autosaves, and restore any post to a specific revision or delete one.

== WooCommerce MCP Integration: 45 AI Tools ==

The most complete WooCommerce MCP server available. Let AI run your entire online store.

* **Product Management (16 tools):** Create and update simple, variable, grouped, or external products, manage stock, SKUs, attributes, and variations, and bulk update.
* **Order Management (11 tools):** List, create, and update orders, change status, edit billing and shipping, add notes, and create full or partial refunds.
* **Customer Management (7 tools):** List, create, update, and delete customers, view purchase history, and segment by spend, order count, or activity.
* **WooCommerce Reports (6 tools):** Sales reports by date range, top-selling products, order and inventory reports, customer acquisition, and coupon usage.

 == Yoast SEO MCP Integration: 15 AI Tools ==

The only WordPress MCP server with dedicated Yoast SEO support. Every field an AI client can write, it can also read back.

* **Read Yoast SEO Data (3 tools):** Get focus keyword, SEO analysis, and the full meta fields bundle in one call — including the SEO title with its template variables already resolved, so you see what search engines will see.
* **Titles & Indexing (5 tools):** Update the SEO title, breadcrumb title, canonical URL, robots meta, and the advanced robots directives (no-image-index, no-archive, no-snippet).
* **Content & Social (5 tools):** Update the meta description, focus keyphrase, Open Graph tags, X/Twitter card, and cornerstone content flag.
* **Structured Data (1 tool):** Set the schema page type and article type used for rich results.
* **Sitemap Management (1 tool):** Add or remove specific posts and pages from the Yoast XML sitemap.

Every Yoast tool works on posts, pages, and any custom post type, so a single call pattern covers your whole site.

== User Management, Roles & Comments: 60 MCP Tools ==

The most complete user and moderation toolkit of any WordPress MCP plugin.

* **User Administration (22 tools):** List, get, search, count, create, and update users, and manage custom user metadata.
* **User Deletion & Validation (5 tools):** Delete with content reassignment, check username and email availability, and get or update user locale.
* **Native Credentials (3 tools):** Reset passwords, invalidate sessions, and validate password reset tokens.
* **Content Association (2 tools):** List posts and comments authored by a specific user.
* **Roles & Permissions (8 tools):** List roles and capabilities, get editable roles, assign or remove roles, and view MCP policy assignments.
* **Comment Moderation, Read (8 tools):** List pending, approved, spam, or trashed comments, search, and get counts by status.
* **Comment Moderation, Status Changes (6 tools):** Approve, unapprove, spam, restore, trash, or permanently delete comments.
* **Comment Content & Metadata (6 tools):** Reply as admin, get and update metadata, and get counts by post, status, or user.

== Contact Form 7, WPForms & Gravity Forms: 40 MCP Tools ==

The only WordPress MCP server with support for the top 3 form plugins.

* **Contact Form 7 (12 tools):** List forms, fields, and mail settings, list and export submissions via Flamingo, mark read/unread, trash, and delete for GDPR.
* **WPForms (14 tools):** List forms, fields, settings, and notifications, list, count, and export entries, mark read/unread, trash, restore, and delete.
* **Gravity Forms (14 tools):** List all or active forms, get fields and settings, list, count, and export entries, mark read/unread, trash, restore, and delete.

== Elementor Page Builder: 10 MCP Tools ==

Elementor keeps its layout in its own data, not in the WordPress editor, which is why asking AI to "update the page content" normally changes nothing a visitor sees. These tools work on the real Elementor layout, so a design you built in Elementor survives the edit.

* **Read Elementor Layouts (3 tools):** List the elements on a page with their text, images, and links, filter by widget type or by the text they contain, and read any widget's full settings schema before writing to it. Reading a layout also reports which text widget holds the article body, so AI editing an article never confuses the caption for the prose.
* **Edit Content Without Touching Design (1 tool):** Change the text, image, or link of a single element. It can only reach content settings, so colours, fonts, and spacing cannot be altered by accident.
* **Edit Styling Deliberately (1 tool):** Set text and background colour, font size, weight and family, line height, letter spacing, letter casing, alignment, padding, and margin — with separate values for tablet and mobile. Restricted to that list on purpose, so the rest of your design is untouchable.
* **Restructure Layouts (4 tools):** Duplicate, move, or delete an element, or replace a whole layout when you are building from scratch.
* **Check Before You Trust It (1 tool):** Render the page, or a single element, to the markup a visitor would receive — so a change can be verified rather than assumed.
* **Duplicate and Rewrite (workflow):** Build one article in Elementor, then have AI duplicate it and replace the copy's text, featured image, categories, and SEO. The copy keeps the original's fonts, colours, spacing, and layout exactly.

Every write is checked after saving and rolled back if it did not persist, and an optional page lock refuses the edit if someone changed the page since AI last read it. Works on posts, pages, and custom post types.

== Custom Post Types & Custom Fields: 19 MCP Tools ==

Works with any custom post type registered on your site, whatever created it — CPT UI, JetEngine, ACF, Toolset, or your own code. Nothing needs configuring first.

* **Discover What Exists (3 tools):** List the custom post types on the site with their taxonomies, supported features, and whether the connected account may edit them; describe one type in detail; and list its items.
* **Read & Edit Items (4 tools):** Read an item with all of its custom fields, update the title, content, excerpt, and slug, create new items, and set the parent for hierarchical types.
* **Custom Fields (2 tools):** Read and write custom field values, including repeatable fields. A field managed by another plugin is reported as read-only instead of being corrupted, and a read tells you exactly why a field cannot be written — so AI never plans an edit that will be refused.
* **Taxonomies & Featured Image (3 tools):** Assign or remove terms in any taxonomy attached to the type, and set the featured image.
* **Publishing & Lifecycle (5 tools):** Change status, trash, restore, duplicate an item as a template, and permanently delete.
* **Page Templates (2 tools):** Read and set the template a single item uses.

Yoast SEO and Elementor tools work on custom post types too, so an events listing or a services directory can be managed end to end.

== Kadence Blocks & Kadence Theme: 17 MCP Tools ==

The first WordPress MCP server that can safely edit a page builder. AI reads and rewrites the actual Kadence block tree, so your layout, styling, and responsive settings survive the edit.

* **Read Kadence Pages (4 tools):** Read a page's full nested block structure by ID or title, get one block's complete settings, search blocks by type or by the text they contain, and list the Kadence block types registered on your site.
* **Edit Kadence Blocks (4 tools):** Update text in 16 separate fields across 9 block types (headings, buttons, info boxes, testimonials, icon lists, image captions, accordion titles), update styling and responsive attributes across all 59 Kadence block types, and duplicate or delete any block.
* **Build Kadence Layouts (7 tools):** Insert rows, columns, headings, images, and buttons, create a new draft page pre-built with a row and columns, or build a nested row-and-column layout in a single request.
* **Kadence Theme Layout (2 tools):** Read and set Kadence's per-page layout options.

Every edit rewrites only the block you target and leaves the rest of the page byte-for-byte identical, and responsive desktop, tablet, and mobile values can be set directly. Kadence tools appear once you grant them to a role in the AI Agent Registry.

== WordPress Site Administration: 35 MCP Tools ==

* **Site Settings, Read (10 tools):** Get title, tagline, URL, timezone, language, permalinks, posts-per-page, homepage, privacy policy page, and search visibility.
* **Site Settings, Write (7 tools):** Update title, tagline, timezone, and posts-per-page, and set homepage, posts page, and privacy policy page.
* **Plugin Management (9 tools):** List, activate, deactivate, delete, and update plugins with confirmation, and get details, version, and author.
* **Theme Management (3 tools):** List installed themes, get the active theme, and get theme details by slug.
* **Updates & Site Health (6 tools):** Check core, plugin, and theme updates, and get WordPress version, PHP/MySQL info, and Site Health status.

== Security & Governance: Why This Is the Safest WordPress MCP Server ==

Letting AI touch your WordPress site is a big trust decision, so our secure MCP server plugin is built with 5 protection layers:

* **Self-Hosted OAuth 2.1 Authorization Server:** Your site is its own OAuth server, so AI never sees your password and only gets a scoped access token.
* **NHI Registry, Role-Based AI Permissions:** Each AI is a first-class identity, and two users connecting the same AI get different permissions based on their role.
* **Per-Tool On/Off Control:** Disable any of the 300+ MCP tools with one click, globally, regardless of role or AI.
* **Real WordPress User Enforcement:** Every MCP request runs as a genuine WordPress user account, so all core capability checks apply.
* **Full Audit Trail:** Every MCP tool call, OAuth grant, and ability change is logged and searchable.

Plus: PKCE (S256) anti-hijack protection, RFC 7591 Dynamic Client Registration for one-click AI setup, Streamable HTTP transport and JSON-RPC 2.0 for reliable connections, Bearer token support for automation, and a clean uninstall.

== Compatible AI Clients ==

This WordPress MCP server works with every major MCP-compatible AI:

* **Claude by Anthropic** (Claude.ai, Claude Desktop, Claude Code): connect in one click via OAuth.
* **ChatGPT by OpenAI:** connect as a custom MCP server.
* **Cursor:** AI code editor with MCP support.
* **Windsurf:** AI-powered IDE.
* **Gemini AI by Google** (Gemini CLI, Google Antigravity).
* **n8n:** automation platform with MCP nodes.
* **Any MCP-compatible client**

== Example Chat Prompts ==

Once you connect Claude or ChatGPT to WordPress with this MCP plugin, just chat:

* "Write a 700-word blog post about summer travel tips and save it as a draft."
* "Update my About page to make the intro shorter and friendlier."
* "Duplicate my best-performing Elementor article, then rewrite it for the UK market and keep the design."
* "The heading on that landing page is too small on mobile — bump it to 34px on mobile only."
* "Add the date and time to the Fred and Friends event, and set its featured image from this URL."
* "Increase prices on all products in 'Winter Collection' by 15%."
* "Which products sold the most this month?"
* "Update the Yoast meta description on my homepage, keep it under 155 characters."
* "Approve every pending comment that isn't spam."
* "Export all Contact Form 7 submissions from the last 30 days as CSV."
* "Check for plugin updates and show me what's available."

== Who Should Use This WordPress MCP Server? ==

* Bloggers using AI to write and publish faster
* WooCommerce store owners automating products, orders, and reports
* SEO agencies delegating Yoast optimization to AI
* Content teams scaling production with AI assistance
* Developers building AI agent workflows on WordPress
* Digital agencies managing multiple client WordPress sites
* Enterprise sites needing secure, role-based AI governance
* Small businesses running lean with AI automation

== Best WordPress MCP Server Comparison ==

**miniOrange Secure MCP vs. Other WordPress MCP Servers**

* **300+ MCP tools included** — miniOrange: Yes | Others: Fewer
* **Role-based AI permissions** — miniOrange: Yes (NHI Registry) | Others: No
* **Per-tool on/off toggle** — miniOrange: Yes | Others: Rare
* **Self-hosted OAuth 2.1** — miniOrange: Full support | Others: Partial
* **WordPress Abilities API** — miniOrange: Native | Others: Some
* **WooCommerce MCP (45 tools)** — miniOrange: Yes | Others: Limited
* **Yoast SEO MCP (15 tools)** — miniOrange: Yes | Others: Limited
* **Elementor MCP (10 tools)** — miniOrange: Yes, edits the real layout | Others: None
* **Kadence Blocks MCP (17 tools)** — miniOrange: Yes | Others: None
* **Custom post types & custom fields (19 tools)** — miniOrange: Any registered type | Others: Rare
* **Contact Form 7, WPForms, Gravity Forms** — miniOrange: All 3 (40 tools) | Others: Rare
* **Node.js or external service needed** — miniOrange: Never | Others: Sometimes
* **Full audit log** — miniOrange: Yes | Others: Sometimes
* **Unlimited usage, no caps** — miniOrange: Yes | Others: Some restrict

== Installation ==

= How to Install the WordPress MCP Server (2 Minutes) =

1. Log in to your WordPress admin dashboard.
2. Go to Plugins &rarr; Add New.
3. Search "Secure MCP Server" 
4. Click Install Now, then Activate.
5. Done â€” the plugin is now active.

= How to Connect Claude to WordPress =

1. Open Tools Secure MCP Server.
2. Go to the "Connect to AI" tab.
3. Copy your MCP server URL: `https://YOUR-SITE/wp-json/mosmcp/v1/mcp`
4. Open Claude Desktop > Settings > Connector> Add custom connector.
5. Paste the URL.
6. Sign in to WordPress.
7. Approve MCP tool access.
8. Start chatting with your site through Claude.

= How to Connect ChatGPT to WordPress =

1. Open Tools Secure MCP Server.
2. Go to the "Connect to AI" tab.
3. Copy your MCP server URL: `https://YOUR-SITE/wp-json/mosmcp/v1/mcp`
4. Open Claude Desktop > Settings > Plugins>Browse Plugins> Add New Plugin.
5. Paste the URL.
6. Sign in to WordPress.
7. Approve MCP tool access.
8. Start chatting with your site through Chagpt.


= Requirements =

* WordPress 6.9 or newer (Abilities API required)
* PHP 7.4 or higher (PHP 8.0+ recommended)
* HTTPS enabled (for OAuth login)

== Frequently Asked Questions ==

= What is the NHI Registry? =

The NHI (Non-Human Identity) Registry is where you create and manage named, role-based ability policies for AI clients. Each NHI maps WordPress roles to the abilities those roles may invoke. When an AI client makes an MCP request, the effective set of allowed abilities is the union â€” across every enabled NHI â€” of the abilities granted to the connecting user's role(s). So two users connecting the same client to the same site can see different tools, based on their roles. You can create as many NHIs as you need and toggle them on or off independently.

= Can I disable an NHI without deleting it? =

Yes. Every NHI has an enable/disable toggle in the NHI Registry screen. A disabled NHI has no effect on MCP requests but its name and ability list are preserved, so you can re-enable it at any time without reconfiguring it.

= Does this WordPress MCP server work with WooCommerce? =

Yes â€” full WooCommerce MCP support with 45 tools covering products, variations, orders, customers, coupons, and reports. Perfect for AI-powered store management.

= Does it work with Yoast SEO? =

Yes. 8 dedicated Yoast SEO MCP tools for meta descriptions, focus keywords, Open Graph tags, robots meta, and sitemap inclusion. Deepest Yoast integration of any WordPress MCP server.

= Does it work with Contact Form 7, WPForms, and Gravity Forms? =

Yes â€” all three form plugins supported with 40 MCP tools total. Read entries, export CSV, moderate submissions, delete for GDPR compliance.

= Is it safe to connect AI to my WordPress site? =

With this MCP plugin, absolutely. AI never sees your WordPress password (OAuth 2.1 with PKCE). Every action is capability-checked at the WordPress core level. Role-based permissions limit what each AI can do. All actions are logged. You can revoke access anytime.

= Can I disable specific MCP tools? =

Yes. Every MCP tool has a global on/off toggle. Turn off dangerous actions like "delete permanently" while keeping safe ones enabled. One-click safety.

= How do I revoke AI access? =

Open the NHI Registry and click Revoke. The AI immediately loses access. You can also disable specific NHIs (preserving their config) or turn off individual tools instantly.

= Can multiple AI connect at once? =

Yes. Connect Claude, ChatGPT, Cursor, and Gemini simultaneously, each with different permissions. Track their activity separately in the audit log.

= Why does the "Source" column show a namespace instead of a plugin name? =

The Abilities API does not record which plugin registered a given ability. The namespace prefix (the part before the slash in the ability name) is the most reliable indicator of where an ability comes from.

== Screenshots ==

1. The onboarding quick-start guide for Secure MCP Server â€” register your first agent and walk through connecting an AI client, step by step.
2. The AI Agents list â€” role-based ability policies for each registered agent, plus which ability packs are ready to use.
3. An agent's Overview tab â€” configuration, the access policy granted to each role, and its latest activity.
4. The Activity Log â€” every ability execution across every agent, filterable by agent, status, ability, user, and date.
5. Connecting an AI client: create a connector in ChatGPT, Claude, or any other MCP-compatible client, then paste the MCP URL via the miniOrange Gateway or a direct connection.
6. The MCP Server Dashboard â€” active agents, total executions, success rate, and average latency at a glance.
7. A member's "Your AI Access" view â€” the tools available to their role, with a one-click path to connect an AI client.

== Changelog ==

= 1.4.8 =
* Minor bug fixes

= 1.4.7 =
* Elementor integration: 10 abilities that let a connected AI client read and edit pages, posts and custom post types built with Elementor. Read a layout's elements with their text, images and links, filter by widget type or by the text they contain, and render the result to check a change rather than assume it.
* Edit Elementor content in place: change the text, image or link of a single element without touching the design. Reading a layout now also reports which text widget holds the article body, so an AI client editing an article no longer has to guess between a caption and the prose.
* Edit selected Elementor styling: text and background colour, font size, weight and family, line height, letter spacing, letter casing, alignment, padding and margin — with separate values for tablet or mobile. Limited to those properties on purpose, so it cannot disturb the rest of a design.
* Custom post type support: 19 abilities for any registered custom post type, whether it came from CPT UI, JetEngine, ACF or code. Read and edit items and their custom fields, assign taxonomy terms, set the featured image, change status, duplicate, trash and restore. Fields managed by another plugin are reported as read-only rather than being corrupted, and a read now tells you exactly why a field cannot be written.
* Media uploads: add files to the media library from a public URL or from supplied file contents, then use the returned attachment ID to set a featured image or fill an image widget. The real file type is checked against the contents rather than the extension, and URL fetches are restricted to public web addresses so the site cannot be used to reach services on its own network.
* Duplication now carries a page's whole appearance — layout, theme settings, taxonomies and featured image — and clears the copy's canonical URL, which would otherwise tell search engines the original is the real page and keep the duplicate out of search results.
* More Yoast fields available to AI clients: breadcrumb title, canonical URL, SEO title, focus keyphrase, social and X/Twitter cards, advanced robots directives, cornerstone content and schema types. Reading a post's SEO now returns every field that can be written, including the title with its template variables resolved.
* Slugs can now be edited on posts, pages and custom post types. Renaming something already published warns that the address has changed, and says plainly when WordPress will not redirect the old one.
* Minor bug fixes

= 1.4.6 =
* Kadence integration: 17 abilities that let a connected AI client read, edit, and build pages made with Kadence Blocks. Read a page's full nested block structure by ID or title, get a single block's settings, and search blocks by type or by the text they contain.
* Edit Kadence blocks in place: update text across 16 fields in 9 block types (headings, buttons, info boxes, testimonials, icon lists, image captions, accordion titles), update styling and responsive attributes across all 59 Kadence block types with desktop/tablet/mobile values, and duplicate or delete a block. Duplicates get freshly generated block IDs so styling never collides.
* Build layouts: insert rows, columns, headings, images (from the media library by attachment ID), and buttons; create a new draft page pre-built with a row and columns; or build a nested row-and-column structure in a single request.
* New abilities to read and set Kadence's per-page theme layout options.
* Kadence edits rewrite only the block you target and leave every other byte of the page identical, so layouts authored in the Kadence editor are preserved. Writes accept an optional page lock so an edit is refused if the page changed since it was last read, and each write returns the previous and new value for verification.
* Inserted Kadence markup is generated to match what the Kadence editor itself saves, including the block version marker, so inserted rows and columns get Kadence's generated CSS and sit side by side as expected. Block types whose markup cannot be reproduced reliably are declined with a message pointing to the duplicate-and-edit route instead of writing markup that would break the page.
* Minor bug fixes

= 1.4.5 =
* ChatGPT is now shown first when connecting a client, with a direct link to the official plugin listing and clearer, more accurate setup steps.
* Added a warning, shown across the plugin, when the site is only reachable on localhost â€” AI clients running elsewhere can't connect to it until it's made publicly reachable.
* Refreshed the sign-in (OAuth authorization) screen to match the rest of the plugin's design.
* Fixed the connection dialogs occasionally rendering behind the WordPress admin toolbar.
* Fixed a brief flicker on the dashboard when it first loads.

= 1.4.4 =
* Onboarding UI improvements in the MCP Server plugin.

= 1.4.3 =
* Minor bug fixes in the MCP Server plugin.

= 1.4.2 =
* Redesigned the role & ability editor as a matrix: abilities are grouped by resource down the left, with every role shown as its own column, so you can compare what each role can do without switching roles one at a time. Includes quick select all / clear controls for an entire role or an entire resource category.
* Added a guided onboarding checklist for new installs: register an agent, add the MCP URL to your AI client, and approve the connection, shown on the dashboard until setup is complete.

= 1.4.1 =
* New ability â€” "Update Page": edits the title and/or content of an existing page.

= 1.4.0 =
* New â€” bundled abilities library: 260+ ready-to-use, security-reviewed WordPress abilities exposed as MCP tools out of the box. Core content (posts, pages, categories, tags, media, revisions), users & roles, and comments are always available; ability sets for WooCommerce, Advanced Custom Fields, Yoast SEO, Contact Form 7 (with Flamingo), WPForms, and Gravity Forms activate automatically when those plugins are present.
* Every bundled ability is capability-gated, carries explicit MCP tool annotations (read-only / destructive / idempotent / open-world), declares full input/output JSON schemas, and is reachable only through the governed MCP endpoint â€” never the public REST API.
* Security hardening in the bundled abilities: reserved user-meta keys (capabilities, role level, session tokens) can never be read or written through an ability; role grants are limited to roles whose capabilities the caller already holds; and CSV entry exports are neutralized against spreadsheet formula injection.
* Fixed the role & ability editor incorrectly flagging object-level abilities (those gated by per-object capabilities such as editing or deleting a specific post or page) as capability conflicts for every role, including Administrator. These capabilities are resolved per request against the target object, so they are no longer shown as conflicts; the runtime permission check is unchanged and was always correct.
* Added a "Test Connection" check that confirms an AI client will actually be able to reach and sign in to your site, run from both your server and an outside vantage so it catches firewall, CDN, and reverse-proxy issues a same-server check would miss. Available on the Connect to AI page and beside Register Agent on the AI Agents screen.
* Added a Troubleshooting guide, always available from the toolbar, that explains the common reasons an AI client can't connect and gives copy-paste Apache/Nginx fixes for each. When a connection test finds an issue, the most likely cause is highlighted automatically.
* Reliability on CDN/cached hosts: the OAuth and MCP endpoints (discovery, registration, the MCP transport, and the authentication challenge) now send "Cache-Control: no-store", so an edge cache or CDN â€” such as Pantheon's Varnish, Cloudflare, WP Engine, or Kinsta â€” can no longer cache and misdeliver these per-request responses, which could otherwise intermittently break AI-client connections.

= 1.3.1 =
* Minor fixes and reliability improvements in the MCP Server plugin.

= 1.3.0 =
* Execution activity log: a full audit trail of every tool call, filterable by agent, status, or time range, with per-event detail including latency and error context.
* Dashboard redesign: four focused metrics â€” Active Agents, Total Executions, Success Rate, and Average Latency â€” for an at-a-glance view of MCP server health.
* Activity timeline on the agent overview: the last 5 executions appear inline on each NHI's overview tab.
* Denied and unknown-tool calls are now correctly attributed to the responsible NHI, so the audit log is never missing an agent name.

= 1.2.3 =
* Clearer role & ability editor: a single Select all / Clear all control (instead of separate All and None buttons), role names shown in each NHI's summary, and a smoother role & ability layout.
* Refined the admin/member view switcher to a clearer segmented control.
* Fixed the support form's country picker so it no longer shifts the page or scrolls unexpectedly when opened.
* General UI polish across the NHI Registry and connection screens.

= 1.2.2 =
* NHI Registry is now role-based: grant abilities to each WordPress role, with live capability-conflict detection. A request receives the abilities its user's role(s) are granted across all enabled NHIs.
* Added a "My AI Access" member view so any logged-in user can see the tools available to their role, with an admin/member view switcher for administrators.
* Rebuilt NHI create/edit as full-screen pages (guided create wizard ending in a connection step); removed the cramped modal editor.
* Existing NHIs are migrated automatically and keep working; review each one to scope its abilities per role.
* Support and deactivation-feedback emails now include the customer's email address in the subject line.
* Added a Settings link to the plugin's row on the Plugins page.

= 1.2.1 =
* Added a floating Contact Support button, available throughout the admin app.
* Added a Setup Guide link in the toolbar for quick access to the connection guide.
* Redesigned the deactivation feedback prompt with a clearer, on-brand layout, guided reason selection, and the option to get help instead of deactivating.

= 1.2.0 =
* Added NHI Registry: a new admin screen to view and manage all non-human identity (AI client) registrations, including OAuth client details and token status.
* Added per-ability toggle to enable or disable individual abilities from being exposed as MCP tools.
* Revamped the plugin UI.

= 1.1.1 =
* Added in-plugin support form and deactivation feedback modal.

= 1.1.0 =
* Added a remote MCP server endpoint that exposes registered abilities as MCP tools.
* Added a self-hosted OAuth 2.1 authorization server with Dynamic Client Registration, PKCE, and discovery metadata so ChatGPT and Claude can connect.

= 1.0.0 =
* Initial release: read-only viewer for abilities registered through the WordPress Abilities API.

== Upgrade Notice ==

= 1.4.7 =
Adds Elementor support, custom post type support for any registered type, media uploads, and the remaining Yoast SEO fields. Newly added abilities start switched off — enable the ones you want under AI Agents. No database changes and no manual upgrade steps required.

= 1.4.6 =
Adds Kadence support: 17 abilities to read, edit, and build pages made with Kadence Blocks, plus Kadence per-page theme layout options and minor bug fixes.

= 1.4.5 =
ChatGPT onboarding improvements, a localhost-reachability warning, and a refreshed sign-in screen. No database changes and no manual upgrade steps required.

= 1.4.4 =
Onboarding UI improvements. No database changes and no manual upgrade steps required.

= 1.4.3 =
Minor bug fixes in the MCP Server plugin

= 1.4.2 =
Redesigns the role & ability editor as a role-by-resource matrix and adds a guided onboarding checklist for new installs. No database changes and no manual upgrade steps required.

= 1.4.1 =
Adds an "Update Page" ability so an AI client can edit pages.

= 1.4.0 =
Adds a bundled library of 260+ ready-to-use WordPress abilities (core content, users & roles, comments, plus sets that auto-activate for WooCommerce, Advanced Custom Fields, Yoast SEO, Contact Form 7, WPForms, and Gravity Forms â€” every ability capability-gated and governed by the NHI Registry), a Test Connection check, and a Troubleshooting guide for diagnosing AI-client connection issues. Also fixes false "capability conflict" warnings for object-level abilities (display-only; permission enforcement is unchanged). No database changes and no manual upgrade steps required.

= 1.3.1 =
Minor fixes and reliability improvements. No upgrade steps required.

= 1.3.0 =
Adds a full execution activity log and a redesigned dashboard. Existing installs are migrated automatically; no manual upgrade steps are required.

= 1.2.3 =
UI refinements to the role & ability editor and general polish and fixes

= 1.2.2 =
Adds role-based access control to the NHI Registry and a member "My AI Access" view. Existing NHIs are migrated automatically and continue to work; review each one to scope its abilities per role. No manual upgrade steps are required.

= 1.2.1 =
Adds a floating Contact Support button, a quick Setup Guide link, and an improved deactivation feedback experience. No database changes or upgrade steps are required.

= 1.2.0 =
Adds the NHI Registry screen for managing connected AI clients, per-ability toggles on the Abilities screen, and a revamped plugin UI. No database changes; no upgrade steps required.

= 1.1.1 =
Adds support and deactivation feedback forms. No database changes; no upgrade steps required.

= 1.1.0 =
Introduces the OAuth-protected MCP server. The plugin now creates database tables; review the updated privacy note in the FAQ.

= 1.0.0 =
Initial release. No upgrade steps required.
