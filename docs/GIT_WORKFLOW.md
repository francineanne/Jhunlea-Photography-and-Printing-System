# Git Workflow — Jhunlea (isang phase = isang branch)

Ito ang patakaran ninyo: **isang tao lang ang nagko-code kada phase.** Gagawa ng branch mula sa pinakabagong `main`, tatapusin, ipapa-review kay Claude sa chat, at kapag aprubado na, ime-merge sa `main`. Pagkatapos noon, kukunin ng susunod ang bagong `main`. Dahil walang sabay na gumagawa, walang magbabanggaang code (walang merge conflict).

```
main ──●────────────●────────────●──────────→
        \          / \          /
         phase-1 ─●   phase-2 ─●        (salitan: Her, BF, Her, BF ...)
```

## Mga patakaran

- **Ang developer lang ang gumagawa ng git write commands:** branch, `add`, `commit`, `push`, `pull`, `merge`, at pag-untrack ng file. **Hindi kailanman nagpapatakbo ng git write command ang Claude Code.** Ang Claude Code ay sumusulat lang ng code, nagpapatakbo ng tests at nag-uulat. Pinapayagan lang sa kanya ang read-only na git: `git status`, `git diff`, `git log`, `git branch --show-current`, `git check-ignore`.
- **Si Claude (sa chat) ang nagre-review**, at ang pag-apruba niya ang pinal. Wala nang partner review at wala nang Pull Request.
- **Ang aprubadong phase ang nagiging bagong `main`.** Ang susunod na phase ay sa branch na galing sa `main` na iyon.
- **Malalaking phase** (halimbawa Phase 1) ay puwedeng hatiin sa mga bahagi (1A, 1B, 1C) sa **iisang branch**. I-commit at i-push ang bawat bahagi bilang backup, pero ang buong branch lang ang ime-merge sa `main` kapag aprubado na ang huling bahagi.

I-type ang lahat ng command sa terminal ng VS Code (`Ctrl + \``), sa loob ng `C:\xampp\htdocs\jhunlea`.

---

## A. One-time setup

### Her (owner ng repo sa GitHub)
1. Sa GitHub repo, pumunta sa **Settings → Collaborators → Add people** at i-invite ang GitHub account ni BF.
2. Huwag i-protect ang `main` na kailangan ng Pull Request, dahil diretso ang push mo sa `main` ayon sa workflow na ito (section F).

### BF (sa sarili niyang laptop)
```bash
cd C:\xampp\htdocs
git clone https://github.com/francineanne/Jhunlea-Photography-and-Printing-System.git jhunlea
cd jhunlea
composer install
copy .env.example .env
php artisan key:generate
```
Gumawa ng database na `jhunlea` sa phpMyAdmin. I-check sa `.env` ang `DB_USERNAME` at `DB_PASSWORD` ng XAMPP (kadalasan `root` at blangko). Pagkatapos:
```bash
php artisan migrate --seed
npm install
```

### Pareho (isang beses lang)
```bash
git config --global user.name "Pangalan Mo"
git config --global user.email "email-mo-sa-github@example.com"
git config --global pull.rebase false
```

---

## B. Pagsisimula ng phase (ang developer na gagawa ng phase)

```bash
git checkout main
git pull origin main
git checkout -b phase-2-catalog       # palitan ng tamang pangalan ng phase
composer install
npm install
php artisan migrate                   # para kapareho ng main ang database mo (huwag migrate:fresh kung may totoong data ka na)
```

## C. Habang nagko-code (paulit-ulit, kahit ilang beses sa isang araw)

Ang developer ang magpapatakbo ng mga ito, hindi si Claude Code:

```bash
git status                            # tingnan kung ano ang nagbago
git add .
git commit -m "Add package gallery upload"
git push -u origin phase-2-catalog    # unang push lang ang may -u
git push                              # sa mga susunod na push
```

Tips:
- Mag-commit pagkatapos ng bawat maliit na natapos na bagay (o bawat bahagi ng malaking phase). Mas madaling bumalik kapag may nasira.
- Malinaw na message: `Add ...`, `Fix ...`, `Update ...`.
- Huwag i-commit ang `.env`, `vendor/` at `node_modules/`. Naka-ignore na ang mga ito.
- Mag-push araw-araw para may backup sa GitHub.

## D. Pagtatapos ng phase (bago ang review)

1. I-check ang **Definition of done** sa `AGENTS.md`:
   ```bash
   php artisan test
   ./vendor/bin/pint --test
   npm run build
   ```
2. I-update ang status table sa `AGENTS.md` at ang checklist sa `docs/DEVELOPMENT_PLAN.md`.
3. I-commit at i-push ang branch (`git add .`, `git status`, `git commit`, `git push`).

### Running the tests safely

Binubura at ginagawa ulit ng mga test ang bawat table na ginagamit nila. Dapat sa in-memory SQLite lang sila tumakbo, hindi kailanman sa `jhunlea` MySQL database.

- Gumamit ng **malinis na terminal**. Huwag kailanman i-export o i-load ang mga value ng `.env` sa shell; kusang binabasa ng Laravel ang `.env`.
- Bago mag-test, patakbuhin ito sa PowerShell. Dapat **walang** lumabas:
  ```powershell
  Get-ChildItem Env: | Where-Object { $_.Name -match '^(APP_|DB_|CACHE_|SESSION_|MAIL_|QUEUE_)' }
  ```
  Kapag may lumabas, hindi ligtas ang terminal na iyon: isara ito at magbukas ng bago. Kung lumalabas pa rin sa bawat bagong terminal, alisin ang mga iyon sa Windows "Edit the system environment variables" (User at System).
- Kapag lumabas ang **"Refusing to run tests: this would touch the database ..."**, pinigilan ng guard ang test bago may nagalaw. Huwag itong lusutan: magbukas ng malinis na terminal. (Detalye: `AGENTS.md`, "Running the tests safely".)

## E. Review at pag-apruba

- Ibibigay ng Claude Code ang final report ng phase. Ipapa-review mo ito kay Claude sa chat.
- Kung may problema, aayusin sa **parehong branch** at magpu-push ulit.
- Kapag **aprubado** na, ang pag-apruba ay pinal. Wala nang Pull Request o partner review.

## F. Merge sa main (pagkatapos ng pag-apruba)

```bash
git add .
git status
git commit -m "Phase 2 — Catalog"          # kung may natitirang pagbabago
git push -u origin phase-2-catalog
git checkout main
git pull origin main
git merge --ff-only phase-2-catalog
git push origin main
```

`--ff-only` ang ginagamit para walang merge commit at tuwid ang history. Kapag nag-error ito (hindi na pwedeng fast-forward), huwag mag-force. Itanong muna kay Claude.

Ang aprubadong phase na ito ang **bagong `main`**.

Optional: burahin ang branch pagkatapos (`git branch -d phase-2-catalog` at "Delete branch" sa GitHub). Nananatili pa rin sa history ang lahat ng commits.

## G. Susunod na phase

Ang susunod na phase ay magsisimula ulit sa **section B**, galing sa bagong `main`, gamit ang bagong pangalan ng branch. Kapag si BF ang susunod, gagawin niya ang `git checkout main` at `git pull origin main` muna.

---

## Kapag may problema

| Problema | Gagawin |
|---|---|
| "Your local changes would be overwritten" | `git stash`, tapos ang command, tapos `git stash pop` |
| Nagkamali ng commit pero hindi pa naka-push | `git reset --soft HEAD~1` para ibalik ang huling commit at panatilihin ang mga pagbabago |
| Naka-`main` pala habang nagko-code | `git checkout -b phase-x-name`. Sasama ang mga pagbabago mo sa bagong branch |
| May error pagkatapos mag-pull | `composer install`, `npm install`, `php artisan migrate`, `php artisan optimize:clear` |
| Nag-error ang `git merge --ff-only` | Huwag mag-force. `git status` at `git log --oneline -5`, tapos itanong kay Claude |
| Hindi sigurado | `git status` at `git log --oneline -5`, tapos itanong kay Claude |
