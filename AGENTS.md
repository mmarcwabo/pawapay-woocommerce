# Project Agent Instructions

Before modifying this repository:

1. Read `docs/ai/PROJECT.md`.
2. Read `docs/ai/DOMAIN.md`.
3. Read `docs/ai/ARCHITECTURE.md`.
4. Read `docs/ai/CONSTRAINTS.md`.
5. Inspect the actual implementation before introducing a new pattern.
6. Preserve backwards compatibility unless the requested change explicitly requires otherwise.
7. Prefer incremental improvement over unnecessary rewrites.
8. Follow existing conventions unless they create a documented correctness, security, maintainability, performance, or operability problem.
9. Document structural architectural decisions in `docs/ai/DECISIONS.md`.
10. Update `docs/ai/AI_CHANGELOG.md` for significant AI-assisted changes.
11. Do not consider a task complete until relevant verification has run.
12. Do not guess unknown project facts. Mark them `UNKNOWN` and identify how they can be confirmed.

For existing projects:

> Understand first. Baseline second. Improve third.

Never refactor blindly.
Never remove unfamiliar code solely because it appears unused.
Never change data structures without considering existing production data.
