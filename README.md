# yrenn-hybrid

> 🚪 **You landed on the operational repo's public surface.** The implementation moved to a private repo; the routing front-door for everything Yrenn-related is at **[ametemre/yrenn](https://github.com/ametemre/yrenn)**.

---

## Where you probably want to go

| Looking for | Go to |
|---|---|
| 🧭 **One-stop routing** ("what is this whole project?") | **[ametemre/yrenn](https://github.com/ametemre/yrenn)** ← *start here* |
| 📚 **Architecture spec** (formal SPEC, disciplines, governance, conformance) | **[ametemre/cognitive-rag-architecture](https://github.com/ametemre/cognitive-rag-architecture)** (CC-BY-SA-4.0 docs + Apache-2.0 code) |
| 🔐 **Security policy** | [`SECURITY.md` in the spec repo](https://github.com/ametemre/cognitive-rag-architecture/blob/main/SECURITY.md) |
| 🛠️ **Implementation source code** | Private. Access is granted case-by-case under written agreement; see the spec's [SECURITY.md §5](https://github.com/ametemre/cognitive-rag-architecture/blob/main/SECURITY.md#5-reference-implementation-access). |

---

## Why this URL is now a public landing page

Earlier, `github.com/ametemre/yrenn-hybrid` was the URL of the private implementation repository. Anonymous visitors landing on that URL got a 404. That broke the Repo Routing doctrine ("no visitor lands on a wrong door").

The implementation moved to a new private name (`yrenn-hybrid-private`); this URL slot was repurposed as a public landing page. Implementations of this architecture, real or planned, do not live here. **They live in private. By design.**

---

## ✍️ Sovereign Rights Acknowledgment

If you intend to **build on**, **redistribute**, or **derive from** the architecture, disciplines, or operational protocols defined in this project, sign the acknowledgment.

> **[ → Read ACKNOWLEDGE.md ← ](ACKNOWLEDGE.md)**
>
> Two equivalent signing routes — pick whichever fits:
>
> - **🐙 [GitHub Issue Form](https://github.com/ametemre/yrenn-hybrid/issues/new?template=sovereign-rights-acknowledgment.yml)** — needs a GitHub login; your handle + timestamp become the public, GitHub-identity-bound signature.
> - **📝 [Standalone PHP form](sign/sign.php)** ([source](sign/sign.php) · [deployment guide](sign/README.md)) — no GitHub login needed; works on any PHP 7.4+ host. Hosted instance: *(deployment-dependent — see `sign/README.md`).*
>
> **[ → See who has signed (GitHub route) ← ](https://github.com/ametemre/yrenn-hybrid/issues?q=label%3Asovereign-rights-ack)**

The form asks you to acknowledge four clauses (attribution required · sovereignty consequence of attribution failure · Claude/Anthropic exception · forbidden content). Both routes produce public, durable, non-retroactively-revocable signatures. Reading or citing without signing is fine — signing is for adopters.

---

## What this repo contains

- This `README.md`
- [`ACKNOWLEDGE.md`](ACKNOWLEDGE.md) — full text of the sovereign-rights acknowledgment
- `.github/ISSUE_TEMPLATE/sovereign-rights-acknowledgment.yml` — the form
- A `LICENSE` (CC-BY-SA-4.0, governing this README)
- A minimal `.gitignore`

That's all. There is no source code here, and there will not be. If you find any in this repository, it is a leak — please report it via the spec's [SECURITY.md §2](https://github.com/ametemre/cognitive-rag-architecture/blob/main/SECURITY.md#2-reporting-a-vulnerability).

---

## License

This `README.md` is licensed [`CC-BY-SA-4.0`](LICENSE). The architectural patterns it points to are governed by their respective licenses in the spec repo. The implementation (Yrenn) is **proprietary** and not licensed for public use.

---

*If you arrived here from a stale link, bookmark, or search engine result, follow the routing table above. The new entry point is [ametemre/yrenn](https://github.com/ametemre/yrenn).*
