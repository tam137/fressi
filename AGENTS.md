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

```
