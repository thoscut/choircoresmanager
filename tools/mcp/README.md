<!--
  - SPDX-FileCopyrightText: 2026 Thomas Scheel and Notenarchiv contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# MCP server for the Notenarchiv

A [Model Context Protocol](https://modelcontextprotocol.io) server that
gives an agent (Claude Desktop, Claude Code or any MCP client) the API for
agents of the Nextcloud app Notenarchiv ([docs/api-v1.md](../../docs/api-v1.md)).
It runs **locally next to the agent** and talks to it over stdio; it opens
no port. It signs in to Nextcloud with an **app password of an account of
its own**.

The agent can read the catalog, a copy with its rights, the due list,
concerts and statistics, build text references, ask questions, answer
questions addressed to its role and propose changes. It cannot decide:
there is no tool (and no route) to confirm rights, change licenses, set the
valid version, accept proposals, delete anything or change settings.

This folder is a separate package (own `package.json` and lock file). It is
not part of the app or its release archive.

## Requirements

- Node.js 24 or newer
- Nextcloud with the app Notenarchiv and the module **Interface for agents**
  (`agent_api`) switched on

## Setting up

1. **An account for the agent.** Create a Nextcloud account that only the
   agent uses (e.g. `notenarchiv-agent`) and a group for it (e.g.
   `Notenarchiv agents`):

   ```sh
   occ group:add notenarchiv-agents
   occ user:add --group notenarchiv-agents notenarchiv-agent
   ```

2. **Only the role it needs.** In *Administration settings → Notenarchiv*
   give the group the role **Proposals (tools)** (`contributor`: read,
   propose, ask) or **Read only** (`reader`). Never give an agent the roles
   of the catalog librarian, operations or the conductor: those decide.

   ```sh
   occ notenarchiv:setup admin --role contributor=notenarchiv-agents
   ```

   (`--role` replaces the groups of that role; list existing groups too.)

3. **Switch on the interface** in *Administration settings → Notenarchiv →
   Modules* ("Interface for agents") or with

   ```sh
   occ notenarchiv:modules enable agent_api
   ```

   It is part of no preset; the presets never switch it on or off. The
   modules of the functions (questions and tasks, proposals inbox,
   concerts, statistics) must be on for their tools.

4. **An app password.** Log in as the agent's account and create one in
   *Personal settings → Security → Devices & sessions*, or get one with the
   login password once:

   ```sh
   curl -u notenarchiv-agent -H 'OCS-APIRequest: true' -H 'Accept: application/json' \
     https://cloud.example.org/ocs/v2.php/core/getapppassword
   ```

   (`occ user:auth-tokens:add` does not work in Nextcloud 33.0.9.) Revoke
   it there, or with `DELETE /ocs/v2.php/core/apppassword`, when the agent
   no longer needs it.

5. **Build the server:**

   ```sh
   cd tools/mcp
   npm ci
   npm run build
   ```

   The server is `tools/mcp/dist/src/index.js`.

## Configuration

| Variable | Required | Meaning |
|---|---|---|
| `NOTENARCHIV_URL` | yes | Address of Nextcloud, e.g. `https://cloud.example.org` or `https://example.org/nextcloud`; https (plain http only for localhost) |
| `NOTENARCHIV_USER` | yes | The agent's account |
| `NOTENARCHIV_APP_PASSWORD` | yes | Its app password |
| `NOTENARCHIV_READ_ONLY` | no | `1`: only the reading tools; nothing is written |

### Claude Desktop

In `claude_desktop_config.json` (*Settings → Developer → Edit Config*):

```json
{
  "mcpServers": {
    "notenarchiv": {
      "command": "node",
      "args": ["/path/to/notenarchiv/tools/mcp/dist/src/index.js"],
      "env": {
        "NOTENARCHIV_URL": "https://cloud.example.org",
        "NOTENARCHIV_USER": "notenarchiv-agent",
        "NOTENARCHIV_APP_PASSWORD": "xxxxx-xxxxx-xxxxx-xxxxx-xxxxx",
        "NOTENARCHIV_READ_ONLY": "1"
      }
    }
  }
}
```

Leave out `NOTENARCHIV_READ_ONLY` when the agent should propose and ask.

### Claude Code

```sh
claude mcp add notenarchiv --scope user \
  -e NOTENARCHIV_URL=https://cloud.example.org \
  -e NOTENARCHIV_USER=notenarchiv-agent \
  -e NOTENARCHIV_APP_PASSWORD="$NOTENARCHIV_APP_PASSWORD" \
  -- node /path/to/notenarchiv/tools/mcp/dist/src/index.js
```

Use the scope `user` or `local`, not `project`: a project scope writes the
app password into `.mcp.json` in the repository.

## Tools

| Tool | Writes | What it does |
|---|---|---|
| `modules` | – | Roles, active modules, permissions, proposable fields; call it first |
| `search_catalog` | – | Search with filters, in pages |
| `get_copy` | – | One copy with version, work, rights, score, files, tasks |
| `text_reference` | – | Text reference to a place (shelf mark · title · page · measures · voice) |
| `list_due` | – | Due list of the own roles |
| `list_tasks` | – | Questions and tasks (mine, open, all) |
| `create_task` | adds | Ask a question or create a task for a role |
| `answer_task` | adds | Answer a task addressed to an own role |
| `propose_change` | adds | Propose values of catalog fields (inbox) |
| `list_my_proposals` | – | The own proposals and their decision |
| `list_concerts`, `get_concert` | – | Concerts and song lists with programme and warnings |
| `statistics` | – | Figures of the archive |

The tools carry MCP annotations: the reading ones `readOnlyHint: true`, the
writing ones `readOnlyHint: false, destructiveHint: false, idempotentHint:
false` (they add a proposal or task and change nothing that exists;
repeating them adds another one). With `NOTENARCHIV_READ_ONLY=1` the writing
tools are not offered at all, and the client refuses every writing request
before it is sent. Results are compact JSON (fields without a value are
left out); errors come back as tool errors with the reason.

## Security

- **The server decides, not the agent.** Every request is checked by
  Nextcloud and the app against the roles of the account; the annotations
  of the tools are hints for the client, not a protection. Give the account
  only the role it needs, and nothing else in Nextcloud (no shares, no
  admin rights).
- **Prompt injection.** Catalog texts (notes, questions, answers) are
  written by people and imported from files; an agent must treat them as
  data. The worst an injected agent can do with the role "contributor" is
  to create proposals and tasks, which people then reject; use read-only
  mode where nothing needs to be written.
- **The app password** stays in the environment of the MCP server. It is
  never logged, never part of a tool result or an error (the server
  replaces it with `[redacted]` even if a server repeats it), never sent in
  a URL and never sent to another host (redirects are not followed). Do not
  put it into files under version control. One app password per agent and
  device; revoke it when it is no longer needed.
- **HTTPS** is required except for `localhost`.
- **Limits.** Nextcloud limits the requests per account (see
  docs/api-v1.md); a runaway agent gets HTTP 429.
- **Audit.** Proposals and tasks are recorded with the agent's account in
  the audit log of the app, like any other change.
- **Switching off.** `occ notenarchiv:modules disable agent_api` closes the
  interface at once (404); deleting the app password or the account ends
  the agent's access.

## Development

```sh
npm ci
npm test          # builds, then runs the tests (node:test, HTTP mocked)
```

The tests check the tool schemas and annotations, the read-only mode, the
mapping of HTTP errors and that no secret appears in any output. The CI
also runs an agent without a language model against a real Nextcloud with
the demo data (`ci/agent-demo.mjs`, called by
`tools/ci/integration-demo.sh`).
