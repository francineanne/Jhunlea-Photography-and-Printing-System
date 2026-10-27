# Git Workflow — Jhunlea (isang phase = isang branch)

Ito ang patakaran ninyo: **isang tao lang ang nagko-code kada phase.** Gagawa ng branch mula sa pinakabagong `main`, tatapusin, ipapa-review, at saka ime-merge sa `main`. Pagkatapos noon, kukunin ng susunod ang bagong `main`. Dahil walang sabay na gumagawa, walang magbabanggaang code (walang merge conflict).

```
main ──●────────────●────────────●──────────→
        \          / \          /
         phase-1 ─●   phase-2 ─●        (salitan: Her, BF, Her, BF ...)
```

I-type ang lahat ng command sa terminal ng VS Code (`Ctrl + \``), sa loob ng `C:\xampp\htdocs\jhunlea`.

---

## A. One-time setup

### Her (owner ng repo sa GitHub)
1. Sa GitHub repo, pumunta sa **Settings → Collaborators → Add people** at i-invite ang GitHub account ni BF.
2. Optional, kung available sa plan ninyo: **Settings → Branches/Rules** at protektahan ang `main` para hindi ito ma-push nang diretso. Kailangang dumaan sa Pull Request.

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

## B. Pagsisimula ng phase (ang gagawa ng phase)

```bash
git checkout main
git pull origin main
git checkout -b phase-2-catalog       # palitan ng tamang pangalan ng phase
composer install
npm install
php artisan migrate:fresh --seed      # para kapareho ng main ang local database mo
```

## C. Habang nagko-code (paulit-ulit, kahit ilang beses sa isang araw)

```bash
git status                            # tingnan kung ano ang nagbago
git add .
git commit -m "Add package gallery upload"
git push -u origin phase-2-catalog    # unang push lang ang may -u
git push                              # sa mga susunod na push
```

Tips:
- Mag-commit pagkatapos ng bawat maliit na natapos na bagay. Mas madaling bumalik kapag may nasira.
- Malinaw na message: `Add ...`, `Fix ...`, `Update ...`.
- Huwag i-commit ang `.env`, `vendor/` at `node_modules/`. Naka-ignore na ang mga ito.
- Mag-push araw-araw para may backup sa GitHub.

## D. Pagtatapos ng phase

1. I-check ang **Definition of done** sa `AGENTS.md`:
   ```bash
   php artisan migrate:fresh --seed
   php artisan test
   ./vendor/bin/pint
   ```
2. I-update ang status table sa `AGENTS.md` at ang checklist sa `docs/DEVELOPMENT_PLAN.md`, tapos i-commit at i-push.
3. Sa GitHub, i-click ang **Compare & pull request**. Base: `main`, compare: `phase-2-catalog`.
   - Title: `Phase 2 — Catalog`
   - Sa description: ano ang natapos, paano i-test, at ano pa ang kulang.

## E. Review ng partner (bago i-merge)

```bash
git fetch origin
git checkout phase-2-catalog
git pull
composer install
npm install
php artisan migrate:fresh --seed
php artisan serve        # terminal 1
npm run dev              # terminal 2
```
- Subukan ang bawat feature sa browser. Mag-login bilang customer at bilang owner.
- Ilagay ang comments sa Pull Request. Ang gumawa ng phase ang mag-aayos sa parehong branch at magpu-push ulit, at awtomatikong maa-update ang PR.
- Kapag ayos na, i-click ang **Approve**.

## F. Merge sa main

Sa GitHub Pull Request, i-click ang **Merge pull request**, tapos **Confirm**. Ito na ang bagong `main`.

Pagkatapos, kayong dalawa:
```bash
git checkout main
git pull origin main
```

Optional: burahin ang branch sa GitHub (may "Delete branch" button). Nananatili pa rin sa history ang lahat ng commits.

## G. Susunod na phase

Ang susunod na gagawa ay babalik sa **step B** gamit ang bagong pangalan ng branch.

---

## Kapag may problema

| Problema | Gagawin |
|---|---|
| "Your local changes would be overwritten" | `git stash`, tapos ang command, tapos `git stash pop` |
| Nagkamali ng commit pero hindi pa naka-push | `git reset --soft HEAD~1` para ibalik ang huling commit at panatilihin ang mga pagbabago |
| Naka-`main` pala habang nagko-code | `git checkout -b phase-x-name`. Sasama ang mga pagbabago mo sa bagong branch |
| May error pagkatapos mag-pull | `composer install`, `npm install`, `php artisan migrate:fresh --seed`, `php artisan optimize:clear` |
| Hindi sigurado | `git status` at `git log --oneline -5`, tapos itanong kay Claude |
