# Contributing

Thanks for helping improve the Modern PHP Field Guide & Starter Kit.

## How to Contribute (Quick Guide)
1. Fork the repo and create a focused branch: `feat/your-change` or `fix/your-change`.
2. Keep contributions practical: real-world snippets, bug fixes, or docs clarifications.
3. Prefer copy-paste-ready examples with secure defaults (`strict_types`, prepared statements, escaping).
4. For new docs, place them under `docs/` with numbered filenames when possible.
5. For runnable examples, keep them framework-free and easy to execute locally.
6. Match existing style: short sections, clear headings, and complete code paths.
7. If you add PHP files, run local lint before submitting:
   `find . -name '*.php' -print0 | xargs -0 -n1 php -l`
8. Update `README.md` links when you add new files or modules.
9. Keep PRs scoped; avoid unrelated refactors in the same change.
10. Write a clear PR description with: what changed, why, and how to test.

## PR Checklist
- [ ] Code/docs are accurate and beginner-friendly
- [ ] Security-sensitive examples use safe patterns
- [ ] All changed PHP files pass `php -l`
- [ ] New files are linked from README/docs index

We welcome improvements for edge cases most tutorials miss.
