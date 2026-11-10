<div align="center">

# 🎾 PadelCamps

**Plugin de WordPress per organitzar campionats de pàdel**
Inscripcions · Sortejos · Quadres · Pistes i horaris · Resultats en directe · Avisos per correu

![Versió](https://img.shields.io/badge/versi%C3%B3-0.1.0-blue)
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
| 📝 **Inscripcions** | Formulari públic configurable per torneig, welcome pack lliure, disponibilitat per franges, consentiments, llista d'espera automàtica i antispam |
| 🗂️ **Gestió** | Llista amb filtres, accions en bloc, control de pagaments, detall per parella i exportació **CSV** |
| 🎲 **Sorteig** | Per llavor, caps de sèrie, grups per bombos, separació de parelles del mateix club, registre d'auditoria (`draw_log`) |
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

```bash
# 1. Clona el repositori dins la carpeta de plugins
cd wp-content/plugins
git clone https://github.com/mananipons/padelcamps.git

# 2. Activa'l des de l'admin de WordPress  →  Plugins → PadelCamps
```

En activar-lo es crea el *schema* de la base de dades (`dbDelta`) i apareix el menú **PadelCamps** a l'admin.

## ⚡ Ús ràpid

1. **PadelCamps → Tornejos** → crea un torneig i les seves categories (tria la plantilla de format).
2. Configura i publica el **Formulari** d'inscripció amb `[padelcamps_registration id="N"]`.
3. Confirma les inscripcions i fes el **Sorteig i quadres** de cada categoria.
4. Defineix pistes i franges a **Calendari i pistes** i programa els partits.
5. Comparteix els enllaços de **Resultats** amb les parelles i mostra el quadre a la web.

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
```

### Blocs Gutenberg

`padelcamps/registration` · `padelcamps/draw` · `padelcamps/schedule`

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

> 🔒 L'API **mai** exposa correus, telèfons, DNI, notes ni dades de pagament. Cada torneig pot mostrar noms complets o només inicials («Anna P.»).

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

Detall complet, decisions i registre de canvis a [`AGENTS.md`](AGENTS.md).

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
- [ ] Tauler real i bloqueig de transicions d'estat
- [ ] Pàgina **Jugadors** (base de dades reutilitzable)
- [ ] Cua d'enviament de correus (Action Scheduler)
- [ ] **Fase 2:** americana, canvis de partit, recordatoris, pagament (Redsys)
- [ ] **Fase 3:** IA assistent, WhatsApp, circuits amb rànquing, SaaS multi-club

## 📄 Llicència

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) · © Finismedia
