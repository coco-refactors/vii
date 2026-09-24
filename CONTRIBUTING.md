# Contributing to VAuLT

VAuLT is an open-source mixed-reality experience platform, released by the University of Oregon under the Apache License 2.0. Contributions are welcome.

## License

By contributing, you agree that your contributions will be licensed under the [Apache License 2.0](LICENSE.txt), the same license as the rest of the project. You keep the copyright in your own contributions; the license gives everyone the right to use them on the project's terms. There is no Contributor License Agreement (CLA).

## Developer Certificate of Origin (DCO)

Every commit must carry a DCO sign-off. The sign-off certifies that you wrote the code, or otherwise have the right to submit it under the Apache License 2.0.

Add the sign-off by committing with the `-s` flag:

```
git commit -s -m "Your commit message"
```

This appends a line like the following to your commit message:

```
Signed-off-by: Your Name <your.email@example.com>
```

Use your real name and an email address you can be reached at. By signing off, you agree to the [Developer Certificate of Origin](https://developercertificate.org/). In short: you wrote the code, or you have the right to submit it under the project's open-source license, and you grant the project the right to use it under that license.

Commits without a sign-off will not be merged. If you forget, you can add a sign-off to your most recent commit with `git commit --amend -s --no-edit`.

## What to Contribute

Good fits:

- Bug fixes and stability improvements
- Canvas LMS integration (through Canvas's public LTI and REST interfaces only; do not copy Canvas source code, which is licensed under the AGPL, into this repository)
- Documentation and accessibility improvements
- Support for new experience formats
- Test coverage

Please open an issue to discuss these before starting work:

- New architectural directions
- Changes to the data model
- New runtime dependencies

## Privacy and Student Data

VAuLT is used in education, and some contributions will handle student data, which is legally protected (for example, under FERPA in the United States and the GDPR in Europe). Do not store student data unless a feature genuinely requires it. Prefer fetching what a feature needs at the moment it needs it and discarding it afterwards. Never commit real personal data, credentials, or API keys to the repository.

## Getting Started

The README describes the repository layout. Each component has its own setup guide: [`vault_web/README.md`](vault_web/README.md) for the backend and [`vault_ios/README.md`](vault_ios/README.md) for the iOS app.

### Contributing from your own fork

1. Fork the repository and clone your fork.
2. Follow the setup guide for the component you are working on.
3. Create a branch: `git checkout -b your-feature-or-fix`
4. Make your changes, signing off every commit (`git commit -s`).
5. Open a pull request against `main`.

### Project teams with commit access

Some teams, such as student project teams, are given direct commit access and a dedicated branch instead of working from a fork. If that applies to you:

1. Send your GitHub username to the maintainers, who will invite you to the repository.
2. Clone the repository and check out the branch you have been assigned.
3. Commit and push to that branch, signing off every commit (`git commit -s`).
4. Do not push to `main`. Your branch's work is reviewed and merged by the maintainers.

## Code Review

All contributions are reviewed before they are merged. Expect feedback; review is collaborative, not adversarial.

## Questions

Open an issue in the repository. Project teams should use the communication channel agreed with the maintainers.

