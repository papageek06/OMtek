# Verification du rendu pret PDF

Date de verification : 06/09/2026

## Attentes du livrable

- Rapport d'activite professionnelle de 30 pages minimum hors annexes.
- Structure attendue : introduction/contexte, developpement missions et projets, analyse reflexive/bilan de competences, conclusion/perspectives.
- Sujet centre sur l'autonomie et la gestion de projet.
- Rapport construit autour d'un projet cle ou d'une contribution importante.
- Integration pertinente de visuels.
- Analyse critique au-dela de la simple description.
- Illustration des soft skills par des situations concretes.
- Qualite de langue, structure, design et mise en page.

## Fichiers produits

- `rapport-activite-professionnelle-pret-pdf.md` : version Markdown prete a retravailler ou exporter.
- `rapport-activite-professionnelle-pret-pdf.docx` : version Word modifiable.
- `rapport-activite-professionnelle-pret-pdf.pdf` : version PDF de controle.

## Verification realisee

- Nombre total de pages PDF : 32.
- Debut des annexes : page 31.
- Corps principal avant annexes : 30 pages.
- Le seuil des 30 pages minimum hors annexes est donc atteint.
- Aucun placeholder `A completer` detecte.
- Aucun placeholder `??` detecte.
- Le DOCX contient 17 visuels integres.
- Le DOCX contient 3 tableaux.

## Points de vigilance avant rendu officiel

- Relire une derniere fois la langue et les accents.
- Verifier visuellement le PDF dans un lecteur PDF local.
- Confirmer que les captures anonymisees conviennent.
- Remplacer le sommaire statique par un sommaire automatique si le rendu final est fait depuis Word.
- Garder les annexes hors du comptage des 30 pages.

## Limite de verification

Le rendu DOCX via LibreOffice n'a pas pu etre execute dans ce runtime Windows, car `soffice.exe` n'est pas disponible. Un PDF direct a donc ete genere avec Python/reportlab et controle avec `pypdf` pour verifier le nombre de pages et la presence du contenu principal. La mise en page a ete resserree pour eviter les pages a moitie vides, puis le texte a ete etoffe avec des sections de fond sur les processus metier, les scenarios utilisateurs, les risques, les tests, l'accompagnement du changement et les indicateurs de reussite.
