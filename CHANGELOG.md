# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Initial release: OAuth2 JWT bearer and client credentials grants with cached tokens.
- Document processes: list, details, drafts with participants and constraints, modification and one-call sending.
- Files: upload, list, removal, download and signed file download.
- Actions: send, withdraw, remind, action discovery, history and participant action links.
- Callbacks: management API, `autenti:callbacks` command and a route verifying `x-jws-signature` with Laravel events.
- Named and runtime connections, acting as another user of the organisation.
