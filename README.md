# Repile for FreeScout

A FreeScout module that connects your help desk to [Repile](https://github.com/rizaardiyanto1412/repile), the AI technical-support agent. It replaces FreeScout's paid API & Webhooks module for Repile and adds features that module cannot offer.

- **Real-time sync.** New tickets, customer replies, agent replies, status changes and deletions reach Repile as they happen. A ticket's Active, Pending or Closed status shows up on its Repile thread.
- **@Repile in notes.** Tag Repile in a note the way you would tag a coworker. Typing `@Re` suggests Repile, a "Repile is looking into Riza's question" line shows while it works, and Repile answers with its own note that starts with `@Riza`. Mentions show as highlighted names.
- **Status card.** Each conversation's sidebar shows what Repile is doing on the ticket, with a link to its Repile thread. "Ask Repile to check again" sits in the conversation's ... menu.
- **A Repile user.** Repile writes its notes and draft replies as a "Repile" user, with its own avatar, that the module creates. Repile only writes drafts; a person still sends every reply, in FreeScout or with **Press send** in Repile.
- **Retries.** Events go through FreeScout's queue. If Repile is unreachable, the module tries again after 30 seconds, then 2 minutes, 10 minutes, 30 minutes, 2 hours and 6 hours.

## Requirements

- FreeScout 1.8 or newer
- PHP 7.4 or newer
- FreeScout's cron running (`php artisan schedule:run` every minute), which also runs the queue

## Install

1. Download **Repile.zip** from the [latest release](https://github.com/rizaardiyanto1412/repile-freescout/releases/latest).
2. Unzip it into FreeScout's `Modules` folder. It already contains a `Repile` folder, so you end up with `Modules/Repile/module.json`.
3. In FreeScout, open **Manage → Modules** and click **Activate** on Repile.

With Docker, copy the folder into the container and run `php artisan freescout:clear-cache` before activating.

Updates appear on the Modules page with an **Update** button when a new release is published.

## Releasing

Bump `version` in `module.json` and push to `main`. The Release workflow sees the new version, builds `Repile.zip` and publishes release `v<version>` with it and `module.json`, which is where FreeScout checks for updates. A version that already has a release is skipped.

## Connect to Repile

1. In FreeScout, open **Manage → Settings → Repile**.
2. Enter your **Repile URL** (it must start with `https://`) and a **Webhook secret** (any long random string), then click **Save**. Saving creates the Repile user.
3. In Repile, open **Settings → FreeScout** and fill in:
   - **Connection:** Repile module
   - **FreeScout URL:** the value the module shows
   - **API key:** the value the module shows
   - **Webhook secret:** the same secret as in step 2
   - **Agent FreeScout user ID:** leave empty. The module only accepts writes as the Repile user and refuses any other user ID.
4. Optional: under **Mailboxes**, tick the mailboxes Repile may work in. Leave them all unticked to include every mailbox.
5. Back in FreeScout, click **Send a test event**. It confirms that Repile is reachable and the secret matches.

### Keeping mailboxes away from Repile

When some mailboxes are ticked under **Mailboxes**, the others are invisible to Repile. Their events are not sent, the API answers `404` for their conversations and leaves them out of lists and `/statuses`, and the Repile card, the "Ask Repile to check again" item and `@Repile` mentions do nothing there. A conversation moved into a ticked mailbox starts syncing on its next event; older history is not sent.

### Private networks

The Repile URL must use `https://` and point at a public address. If Repile runs on the same server or inside your own network, tick **Repile runs on a private network**. That allows local and private addresses, and plain `http://` for `localhost`. Without it, the module refuses to save or send to loopback, link-local (such as `169.254.169.254`) and private addresses.

### Sensitive data

- **Redact credentials** (off by default) replaces passwords, tokens, API keys and logins inside links with `[redacted]` in everything sent to Repile: events and API responses. FreeScout's own copy is left as it is. It catches labelled values (`Password: ...`, `senha=...`, `API key: ...`), `https://user:pass@host` and common key formats (`sk-...`, `ghp_...`, AWS access keys). It is a best guess and misses credentials written as plain sentences, so ask customers to share logins through a secret-sharing link.
- **Keep internal notes from Repile** (off by default) leaves notes out of `?_embed=threads`, except notes that mention `@Repile` and Repile's own notes.

Queued events hold only IDs. The ticket text is read when the event is sent, so it never sits in the `jobs` or `failed_jobs` tables, and a retry sends the current version.

If the paid API & Webhooks module also sends webhooks to Repile, remove that webhook so Repile does not get every event twice.

## What Repile receives

Every event is a `POST` to `{Repile URL}/api/v1/plugins/freescout/http/webhook` with a JSON conversation body and these headers:

| Header | Value |
|---|---|
| `X-FreeScout-Event` | The event name |
| `X-FreeScout-Signature` | base64 HMAC-SHA1 of the body, keyed by the webhook secret (same as the paid module) |
| `X-Repile-Timestamp` | Unix time the request was sent |
| `X-Repile-Signature` | hex HMAC-SHA256 of `{timestamp}.{body}`, keyed by the webhook secret |
| `X-Repile-Delivery` | A new UUID for every attempt |

To stop replays, verify `X-Repile-Signature`, reject timestamps more than 5 minutes from your clock, and optionally ignore delivery IDs you have already seen. `X-FreeScout-Signature` covers only the body, so a captured request stays valid forever.

If a conversation was deleted for good before its `convo.deleted` event went out, the body is just `{"id": 123, "state": "deleted"}`.

| Event | Sent when |
|---|---|
| `convo.created` | A customer opens a ticket |
| `convo.customer.reply.created` | The customer replies |
| `convo.agent.reply.created` | A person sends a reply |
| `convo.status` | The status changes |
| `convo.deleted` | The conversation is deleted |
| `repile.mention` | A note mentions `@Repile` (adds `mention.text` and `mention.user`) |
| `repile.recheck` | Someone picks **Ask Repile to check again** |
| `repile.ping` | Someone presses **Send a test event** |

Repile answers with the thread it used (`threadId`, `threadPath`), which the panel links to. A `threadPath` must start with a single `/` and contain no `@`, backslash or whitespace, otherwise the link uses `/threads/{threadId}`.

## What Repile can call

Repile calls `{FreeScout URL}/repile/api` with the header `X-FreeScout-API-Key`:

| Call | Does |
|---|---|
| `GET /mailboxes` | Lists the mailboxes Repile may use |
| `GET /conversations?mailboxId=&status=&page=` | Lists conversations |
| `GET /conversations/{id}?_embed=threads` | Reads a conversation with its threads |
| `POST /conversations/{id}/threads` | Adds a note (`type: note`) or replaces Repile's draft reply (`type: message`, `state: draft`) |
| `POST /conversations/{id}/threads/{threadId}/send` | Sends Repile's draft reply to the customer, optionally with new `text`. Only works on a draft the Repile user wrote and nobody edited in FreeScout |
| `PUT /conversations/{id}` | Changes the status or assignee (`assignTo` must be someone who can be assigned in that mailbox) |
| `POST /statuses` | Returns the status of up to 500 conversations at once |

Repile never sends on its own. The send call exists for the **Press send** button on the draft card in Repile, so a person still sends every reply, either there or in FreeScout. It refuses any thread that is not Repile's own unedited draft.

Every write is made as the Repile user. `user` (threads) and `byUser` (status and assignee) may be left out or set to the Repile user's ID; any other ID gets `422 {"message": "Repile can only act as the Repile user"}`. Notes and drafts that contain HTML are cleaned to paragraphs, line breaks, bold, italics, lists, code, quotes and `http(s)` links, so images, styles and hidden content never get stored.

## Tests

The feature tests in `Tests/` run inside a FreeScout checkout with its dev dependencies installed and a test database configured:

```
vendor/bin/phpunit --stderr Modules/Repile/Tests
```

`--stderr` matters: FreeScout's middleware sends headers directly, which fails once PHPUnit has printed to stdout.

## License

AGPL-3.0, like FreeScout.
