# Repile for FreeScout

A FreeScout module that connects your help desk to [Repile](https://github.com/rizaardiyanto1412/repile), the AI technical-support agent. It replaces FreeScout's paid API & Webhooks module for Repile and adds features that module cannot offer.

- **Real-time sync.** New tickets, customer replies, agent replies, status changes and deletions reach Repile as they happen. A ticket's Active, Pending or Closed status shows up on its Repile thread.
- **@Repile in notes.** Tag Repile in a note the way you would tag a coworker. Typing `@Re` suggests Repile, a "Repile is looking into Riza's question" line shows while it works, and Repile answers with its own note that starts with `@Riza`. Mentions show as highlighted names.
- **Status card.** Each conversation's sidebar shows what Repile is doing on the ticket, with a link to its Repile thread. "Ask Repile to check again" sits in the conversation's ... menu.
- **A Repile user.** Repile writes its notes and draft replies as a "Repile" user, with its own avatar, that the module creates. Repile only writes drafts; a person still sends every reply.
- **Retries.** Events go through FreeScout's queue. If Repile is unreachable, the module tries again after 30 seconds, then 2 minutes, 10 minutes, 30 minutes, 2 hours and 6 hours.

## Requirements

- FreeScout 1.8 or newer
- PHP 7.4 or newer
- FreeScout's cron running (`php artisan schedule:run` every minute), which also runs the queue

## Install

1. Download the [latest zip](https://github.com/rizaardiyanto1412/repile-freescout/archive/refs/heads/main.zip).
2. Unzip it into FreeScout's `Modules` folder and rename the folder to `Repile`, so the path is `Modules/Repile/module.json`.
3. In FreeScout, open **Manage → Modules** and click **Activate** on Repile.

With Docker, copy the folder into the container and run `php artisan freescout:clear-cache` before activating.

Updates appear on the Modules page with an **Update** button.

## Connect to Repile

1. In FreeScout, open **Manage → Settings → Repile**.
2. Enter your **Repile URL** and a **Webhook secret** (any long random string), then click **Save**. Saving creates the Repile user.
3. In Repile, open **Settings → FreeScout** and fill in:
   - **Connection:** Repile module
   - **FreeScout URL:** the value the module shows
   - **API key:** the value the module shows
   - **Webhook secret:** the same secret as in step 2
   - **Agent FreeScout user ID:** leave empty, since Repile writes as the Repile user
4. Back in FreeScout, click **Send a test event**. It confirms that Repile is reachable and the secret matches.

If the paid API & Webhooks module also sends webhooks to Repile, remove that webhook so Repile does not get every event twice.

## What Repile receives

Every event is a `POST` to `{Repile URL}/api/v1/plugins/freescout/http/webhook` with a JSON conversation body. It carries the headers `X-FreeScout-Event` and `X-FreeScout-Signature`, where the signature is base64 HMAC-SHA1 of the body keyed by the webhook secret.

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

Repile answers with the thread it used (`threadId`, `threadPath`), which the panel links to.

## What Repile can call

Repile calls `{FreeScout URL}/repile/api` with the header `X-FreeScout-API-Key`:

| Call | Does |
|---|---|
| `GET /mailboxes` | Lists mailboxes |
| `GET /conversations?mailboxId=&status=&page=` | Lists conversations |
| `GET /conversations/{id}?_embed=threads` | Reads a conversation with its threads |
| `POST /conversations/{id}/threads` | Adds a note (`type: note`) or replaces Repile's draft reply (`type: message`, `state: draft`) |
| `PUT /conversations/{id}` | Changes the status or assignee |
| `POST /statuses` | Returns the status of up to 500 conversations at once |

The module refuses to send replies. Repile can only write drafts.

## License

AGPL-3.0, like FreeScout.
