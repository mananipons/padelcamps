<div align="center">

# 🎾 PadelCamps

**Plugin de WordPress per organitzar campionats de pàdel**
Inscripcions · Sortejos · Quadres · Pistes i horaris · Resultats en directe · Avisos per correu

![Versió](https://img.shields.io/badge/versi%C3%B3-0.2.0-blue)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.0-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b?logo=wordpress&logoColor=white)
![Llicència](https://img.shields.io/badge/llic%C3%A8ncia-GPL--2.0--or--later-green)
![Estat](https://img.shields.io/badge/estat-en%20desenvolupament-orange)

</div>

---

## 📋 Index

- [Què és](#-què-és)
- [Funcionalitats](#-funcionalitats)
- [Requisits](#-requisits)
- [Instal·lació](#-installació)
- [Ús ràpid](#-ús-ràpid)
- [Shortcodes, blocs i API](#-shortcodes-blocs-i-api)
- [Arquitectura](#-arquitectura)
- [Desenvolupament](#-desenvolupament)
- [Traduccions](#-traduccions)
- [Full de ruta](#-full-de-ruta)

## 🏆 Què és

PadelCamps cobreix tot el cicle d'un torneig de pàdel dins la web WordPress del club o circuit: des que la parella s'inscriu fins que es corona el campió. Pensat per a **circuits comarcals** que avui publiquen quadres en PDF estàtics.

> **Principi de disseny:** el sorteig és **determinista, no IA**. La mateixa llavor dona sempre el mateix resultat, així és auditable i justificable davant dels jugadors.

## ✨ Funcionalitats

| Àrea | Què fa |
|---|---|
| 📊 **Tauler** | Torneig actiu amb les xifres clau en gran (parelles, jugadors, cobrat, partits jugats i d'avui, resultats per revisar, correus), llista de feines pendents, progrés per categoria i totals de tots els tornejos |
| 🏷️ **Tornejos i categories** | Espai de treball per torneig amb pestanyes, **edició en bloc** de categories (consolació, classificats, format dels partits, desempats, places, preu) i eliminació completa d'un torneig amb confirmació |
| 📝 **Inscripcions** | Formulari públic configurable per torneig, welcome pack lliure, disponibilitat per franges, consentiments, llista d'espera automàtica i antispam |
| 🗂️ **Gestió** | Llista amb filtres, accions en bloc, **pagament per jugador** (el preu és per jugador i cadascú paga el seu: pagat, pagat en part, pendent, reemborsat), resum de cobrat / pendent de cobrar (avisa quan els totals són d'un filtre), detall per parella i exportació **CSV** |
| ✍️ **Inscripció manual** | L'organitzador dona d'alta parelles des de l'admin (telèfon, WhatsApp, al club) triant estat, pagament i idioma dels correus, i edita les dades dels jugadors |
| 🎲 **Sorteig** | Per llavor, **caps de sèrie pels punts de la temporada** (suma dels dos jugadors, amb la puntuació manual de desempat), grups per bombos, separació de parelles del mateix club, registre d'auditoria (`draw_log`) |
| 🏅 **Rànquing de temporada** | Temporades amb categories de rànquing pròpies (1a/2a/3a, B+/B/C…), punts per ronda assolida **editables**, l'organitzador tria quins tornejos puntuen, **publicació manual** dels punts per categoria (amb avís si un resultat canvia després), ajustos manuals amb motiu i rànquing públic per categoria |
| 👥 **Jugadors** | Base de dades única identificada pel **DNI/NIE** (obligatori): cerca, fitxa amb inscripcions i punts, edició, **fusió de duplicats** amb confirmació, esborrat compatible amb l'RGPD i rànquing per temporada i categoria |
| 🧩 **Formats** | Fase de grups + eliminatòria, tots contra tots, eliminatòria directa, quadre de consolació |
| 🏟️ **Calendari i pistes** | Planificador de pistes × franges amb durada, descans mínim, disponibilitat i detecció de conflictes |
| 📱 **Resultats des del mòbil** | Un *magic link* per parella (sense compte WP), confirmació per la parella rival i gestió de disputes |
| 📺 **Part pública** | Quadres i ordre de joc **en directe**, cercador «Busca la teva parella», pantalla TV per al club i blocs Gutenberg |
| ✉️ **Comunicació** | Avisos automàtics, horaris per parella amb detecció de canvis i missatges lliures, en l'idioma de cada jugador |
| 🌍 **Multiidioma** | Català (llengua principal), castellà i anglès amb capa de traducció pròpia editable des de l'admin |

## ⚙️ Requisits

- **PHP** 8.0 o superior
- **WordPress** 6.0 o superior
- MySQL / MariaDB (el plugin crea les seves pròpies taules, prefix `{wpdb->prefix}pc_`)

## 🚀 Instal·lació

### Opció 1 · Des del ZIP (recomanada)

1. Descarrega **`padelcamps-X.Y.Z.zip`** de l'apartat [**Releases**](https://github.com/mananipons/padelcamps/releases/latest) (secció *Assets*).
2. A WordPress: **Plugins → Afegeix nou → Puja el connector**, selecciona el ZIP i prem **Instal·la ara**.
3. Prem **Activa el connector**.

> No descarreguis el botó verd *Code → Download ZIP*: aquest ZIP porta la carpeta `padelcamps-main` i WordPress el instal·laria amb un nom incorrecte.

### Opció 2 · Amb Git

```bash
cd wp-content/plugins
git clone https://github.com/mananipons/padelcamps.git
# Activa'l des de l'admin de WordPress  →  Plugins → PadelCamps
```

En activar-lo es crea el *schema* de la base de dades (`dbDelta`) i apareix el menú **PadelCamps** a l'admin.

## ⚡ Ús ràpid

1. **PadelCamps → Tornejos** → crea un torneig i les seves categories (tria la plantilla de format).
2. Configura i publica el **Formulari** d'inscripció amb `[padelcamps_registration id="N"]`.
3. Confirma les inscripcions (o afegeix-ne a mà amb **Afegeix una inscripció**) i fes el **Sorteig i quadres** de cada categoria.
4. Defineix pistes i franges a **Calendari i pistes** i programa els partits.
5. Comparteix els enllaços de **Resultats** amb les parelles i mostra el quadre a la web.
6. Segueix-ho tot des del **Tauler** (PadelCamps → Tauler).

**Rànquing de temporada:** a **Temporades** crea una temporada, defineix-ne les categories de rànquing i els punts per resultat; al torneig tria la temporada, marca si dona punts i enllaça cada categoria amb una de rànquing. Quan una categoria acaba, a **Resultats → Punts del rànquing** publica'n els punts. Mostra'l a la web amb `[padelcamps_ranking]`. Cal el **DNI o NIE** dels dos jugadors a cada inscripció: és el que identifica una persona a tots els tornejos.

Per aplicar el mateix canvi a diverses categories (p. ex. afegir quadre de consolació a totes), marca-les a **Configuració** i fes servir **Edita en bloc les categories seleccionades**. Per eliminar un torneig amb totes les seves dades: **Resum → Zona de perill** (cal escriure el nom del torneig per confirmar).

Cicle de vida d'un torneig:

```
draft → registration_open → registration_closed → drawn → in_progress → finished
```

## 🔌 Shortcodes, blocs i API

### Shortcodes

```text
[padelcamps_registration id="N"]                       Formulari d'inscripció
[padelcamps_draw         id="N" category="M" live="1" find="1"]   Quadres i classificacions
[padelcamps_schedule     id="N" category="M" live="1" find="1"]   Ordre de joc (dia, hora, pista)
[padelcamps_ranking      season="N" category="M"]      Rànquing de la temporada (sense season = l'actual)
```

### Blocs Gutenberg

`padelcamps/registration` · `padelcamps/draw` · `padelcamps/schedule` · `padelcamps/ranking`

### Pantalla TV

```text
https://el-teu-site/?padelcamps_tv=ID
```

### API REST pública (`padelcamps/v1`)

| Mètode | Ruta | Descripció |
|---|---|---|
| `GET` | `/tournaments` | Llista de tornejos públics |
| `GET` | `/tournaments/{id}/draw[?category=M]` | Quadres i classificacions (JSON) |
| `GET` | `/tournaments/{id}/schedule[?category=]` | Ordre de joc (JSON) |
| `GET` | `/tournaments/{id}/view?type=draw\|schedule\|tv` | HTML per al mode en directe |
| `GET` | `/seasons/{id}/ranking[?category=M]` | Rànquing d'una categoria de la temporada (JSON) |

> 🔒 L'API **mai** exposa correus, telèfons, DNI, club, notes ni dades de pagament. Cada torneig pot mostrar noms complets o només inicials («Anna P.»). El rànquing públic només mostra posició, nom, tornejos jugats i punts, amb **inicials per defecte** (cada temporada pot triar noms complets).

## 🏗️ Arquitectura

```text
padelcamps/
├─ padelcamps.php      Capçalera del plugin, constants i hooks d'activació
├─ src/
│  ├─ Engine/          Lògica pura, SENSE dependències de WordPress
│  ├─ Db/              Schema (dbDelta) i repositoris
│  ├─ Service/         Fluxos de negoci (sorteig, resultats, calendari, comunicació)
│  ├─ Admin/           Pantalles i pestanyes de l'admin
│  ├─ Frontend/        Shortcodes, blocs, pàgina de parella, pantalla TV
│  ├─ Notifications/   Correus
│  ├─ I18n/            Escàner de cadenes i traductor
│  └─ Rest/            API REST
├─ assets/             CSS i JS (públic i blocs)
├─ languages/          es.php, en.php
└─ tests/run.php       Tests autònoms
```

**Decisions clau**

- El **motor** (`src/Engine`) és PHP pur: es pot reutilitzar en un SaaS futur. L'API REST és la frontera entre motor i interfícies.
- **Taules pròpies** (`$wpdb` / `dbDelta`), no CPT ni *post meta*.
- **Jugadors sense compte WP:** *magic links* amb token per parella.
- Espai de noms PHP `PadelCamps\`, autoloader PSR-4 mínim.



## 🧪 Desenvolupament

Tests del motor (a l'arrel del plugin):

```bash
php tests/run.php        # ha de dir: OK: N checks passed
```

Lint d'un fitxer:

```bash
php -l src/Plugin.php
```

Regles per contribuir:

- Codi i comentaris en **anglès**; cadenes visibles en **català** amb el domini `padelcamps`.
- Res de `src/Engine/` ni `I18n/Catalog.php` pot cridar funcions de WordPress.
- Les classes amb WP comencen amb `defined( 'ABSPATH' ) || exit;`.

## 🔄 Actualitzacions des de WordPress

El plugin s'actualitza des de **Plugins → Actualitzacions** de WordPress, igual que un plugin de wordpress.org: consulta l'última *release* de GitHub (cada 6 h) i, si la versió és més nova, ofereix l'actualització amb un clic. Per forçar la comprovació: **Tauler → Actualitzacions → Comprova-ho de nou**.

El repositori és **públic**, així que **no cal cap configuració**: instal·la el ZIP i les actualitzacions arribaran soles.

> ⚠️ Si tens definit un `PADELCAMPS_GITHUB_TOKEN` **invàlid o caducat**, GitHub rebutja les peticions (401) fins i tot amb el repositori públic. Treu-lo del `wp-config.php` o canvia'l per un de vàlid.

<details>
<summary>Si el repositori tornés a ser privat</summary>

Cada web necessita un *token* de només lectura:

1. A GitHub: **Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token**. Repositori: només `padelcamps`. Permís: **Contents → Read-only**.
2. Afegeix-lo al `wp-config.php` de la web, abans de la línia *That's all, stop editing!*:

   ```php
   define( 'PADELCAMPS_GITHUB_TOKEN', 'github_pat_xxxxxxxx' );
   ```

El token només s'envia a `api.github.com`. Es pot canviar de repositori amb `PADELCAMPS_GITHUB_REPO` (format `usuari/repo`).

</details>

## 📦 Publicar una versió

El ZIP instal·lable es genera sol amb GitHub Actions ([release.yml](.github/workflows/release.yml)):

1. Puja la versió a `padelcamps.php` (capçalera `Version:` **i** constant `PADELCAMPS_VERSION`).
2. Fes commit i push a `main`.
3. Crea i puja el tag:

   ```bash
   git tag v0.2.0
   git push origin v0.2.0
   ```

GitHub comprova que el tag coincideix amb la versió del plugin, construeix `padelcamps-0.2.0.zip` i el penja a la [release](https://github.com/mananipons/padelcamps/releases).

## 🌍 Traduccions

Capa pròpia, sense `.po/.mo`. Les cadenes font són en català; cada cadena nova s'ha d'afegir a `languages/es.php` i `languages/en.php`. `php tests/run.php` falla si en falta alguna, si en sobra o si els marcadors `%s/%d` no coincideixen.

Des de l'admin, **PadelCamps → Traduccions** permet editar qualsevol cadena per idioma. Es poden afegir idiomes amb el filtre `padelcamps_languages`.

## 🗺️ Full de ruta

- [x] Model de dades, motor de sorteig i quadres
- [x] Formulari d'inscripció i gestió d'inscripcions
- [x] Sorteig, grups, classificació i consolació
- [x] Calendari i pistes
- [x] Resultats per *magic link*
- [x] Part pública en directe, TV i blocs
- [x] Comunicació per correu
- [x] Inscripció manual des de l'admin i edició de jugadors
- [x] Edició en bloc de categories i eliminació completa de tornejos
- [x] Tauler amb el torneig actiu i estadístiques
- [ ] Bloqueig de transicions d'estat segons el sorteig i els partits
- [x] Admin amb full d'estils propi (caixes amb padding, cantonades de 6px com a màxim)
- [x] Pàgina **Jugadors** (identitat per DNI/NIE, historial, fusió de duplicats) i **rànquing de temporada** entre tornejos, amb rànquing públic i caps de sèrie pels punts
- [ ] Rànquing: sembrar també els tornejos que no puntuen, suggeriment automàtic de duplicats, club al rànquing públic, importar/exportar jugadors
- [ ] Pàgina **Clubs** (clubs reutilitzables; ara placeholder al menú)
- [ ] Opció de configuració **Activar llavors als sortejos**: permet fixar una llavor (número o text) perquè el sorteig es pugui repetir idèntic o justificar davant d'una queixa. Desactivada per defecte (sorteig aleatori pur, amb llavor interna al registre d'auditoria)
- [ ] Comunicació: cua d'enviament (Action Scheduler), correus en HTML, plantilles per torneig, recordatori el dia abans
- [ ] Inscripció manual: canviar la categoria d'una inscripció, welcome pack / disponibilitat
- [ ] **Fase 2:** americana, canvis de partit, recordatoris, pagament (Redsys)
- [ ] **Fase 3:** IA assistent, WhatsApp, circuits amb rànquing, SaaS multi-club

## 📄 Llicència

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) · © Finismedia
