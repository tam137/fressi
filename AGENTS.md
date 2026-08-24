# AGENTS.md — Project & Server Guide for `fressi.example.com`

Central guidelines for AI agents working on the **fressi** web application.
Agents do not change this AGENTS.md file.

---

## 1. Project Overview & Architecture

* **Tech Stack:** Single responsive page built with PHP 8.1, Vanilla JavaScript (`src/js/app.js`), and Vanilla CSS (`src/css/style.css`). Needs highly optimized for mobile displays.

---

## 2. Mandatory Rules for AI Agents

> [!IMPORTANT]
> 1. **Planning Mode:** Every code, config, or server change **must** be documented in `implementation_plan.md` and approved by the user before execution.
> 2. **Git Deployment:** Only by user action
> 3. **Languages & Tone:**
>    - **UI Text:** German, informal ("Du" / "Duzen").
>    - **Code, Comments, Git Commits:** English.
> 4. **Security & Privacy:** Never commit secrets, passwords, or absolute local paths (`/home/user/...`).

---

## 3. Git Commit Convention

> [!IMPORTANT]
> Commit and push **only** when the user explicitly asks for it (see rule 2 above).

* **Branch:** Commit straight to `master` and push with `git push origin master`.
  The history is linear — do not create feature branches.
* **Message format:** A **single subject line**. No body, no bullet lists, no footers.
* **No trailers:** Never append `Co-Authored-By`, `Claude-Session`, `Generated with ...`
  or any other trailer. This overrides the default behaviour of any AI coding tool.
* **Style:** English, imperative mood, no trailing period, roughly 50–85 characters.
  Name what changed and why it matters, not which files were touched.
* **Scope:** One commit per change; stage only the files belonging to that change.

Examples from the existing history:

```text
Scale favorites to 100% portion and calories by default
Decouple favorites from meal history deletion
Improve build timestamp detection to include helper files and git paths
```

