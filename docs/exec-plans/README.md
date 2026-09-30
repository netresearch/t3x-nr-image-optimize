<!-- SPDX-License-Identifier: GPL-3.0-or-later -->
<!-- SPDX-FileCopyrightText: Netresearch DTT GmbH -->
# Execution plans

Working directory for multi-step agent execution plans (design docs, migration plans, task breakdowns) that are too large for a PR description.

- `active/` — plans currently being executed (create on demand)
- `completed/` — finished plans kept for archaeology (create on demand)

Keep plans out of `AGENTS.md` — that file describes the stable state of the repo, not in-flight work. A plan that changed the architecture must update `docs/ARCHITECTURE.md` in the same PR.
