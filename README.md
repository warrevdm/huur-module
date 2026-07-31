# Aerts Action Bike — interne verhuurmodule

PHP 8.2-MVP voor de interne planning en administratie van verhuurfietsen.

## Functionaliteit

- Horizontale agenda met één rij per fiets en verhuurblokken over meerdere dagen.
- Vrije dagen zijn klikbaar om een reservatie met voorgeselecteerde fiets en datum te starten.
- Fietsbeheer met interne code, categorie, framemaat, dagprijs en status.
- Reservaties met klantgegevens, afhaal- en retourmoment, prijs, notities en status.
- Server-side blokkering van overlappende reservaties voor dezelfde fiets.
- Veilige upload van identiteitsdocumenten in JPG, PNG of PDF.
- ID-bestanden worden buiten de publieke webmap opgeslagen met een willekeurige bestandsnaam.
- ID-bestanden zijn uitsluitend na login toegankelijk en downloads worden gelogd.
- Een bewaartermijn kan per identiteitsdocument worden ingesteld.
- Auditlog voor login, reservaties, statuswijzigingen en documenttoegang.

## Vereisten

- PHP 8.2 of hoger
- PHP-extensies `pdo_sqlite` en `fileinfo`
- Schrijfrechten op de map `storage/`
- De webserver document root moet naar `public/` wijzen
- HTTPS in productie

## Lokaal starten

```bash
cp .env.example .env
php bin/setup.php
php -S localhost:8080 -t public
```

Open daarna `http://localhost:8080`.

Wijzig vóór de eerste setup minstens deze waarden in `.env`:

```dotenv
ADMIN_EMAIL=beheer@voorbeeld.be
ADMIN_PASSWORD=gebruik-een-sterk-wachtwoord
```

## Productie-installatie

1. Kopieer `.env.example` naar `.env`.
2. Stel een uniek en sterk beheerderswachtwoord in.
3. Stel de document root van het domein of subdomein in op `public/`.
4. Zorg dat `storage/` niet rechtstreeks via de webserver bereikbaar is.
5. Controleer dat PHP `pdo_sqlite` en `fileinfo` geladen zijn.
6. Voer `php bin/setup.php` één keer uit.
7. Activeer HTTPS en maak een databaseback-upbeleid.

## ID-upload en privacy

De module controleert het werkelijke MIME-type via PHP `fileinfo`, limiteert de bestandsgrootte via `ID_MAX_MB`, gebruikt willekeurige bestandsnamen en bewaart documenten onder `storage/private/ids/`.

Een volledige kopie van een identiteitskaart bevat vaak meer persoonsgegevens dan nodig. Leg intern vast:

- waarom de kopie noodzakelijk is;
- welke gegevens zichtbaar moeten zijn;
- wie toegang krijgt;
- hoe lang het document bewaard wordt;
- hoe de klant hierover geïnformeerd wordt.

De standaard bewaartermijn staat op 30 dagen na de retourdatum en is instelbaar via `ID_RETENTION_DAYS`. De verwijderdatum wordt opgeslagen; een automatische cron-opruimtaak moet nog als aparte productie-hardening worden toegevoegd.

## Belangrijkste routes

- `?route=planning`
- `?route=reservation-new`
- `?route=reservation-view&id=1`
- `?route=bikes`

## Datamodel

- `users`
- `bikes`
- `customers`
- `reservations`
- `identity_documents`
- `audit_logs`

## Volgende uitbreidingen

- Reservaties wijzigen en een fiets omboeken.
- Onderhoudsblokken rechtstreeks in de planning.
- Accessoires per verhuur: helm, slot, lader en kinderzitje.
- Schadecheck met foto's bij afhaling en retour.
- Digitale huurovereenkomst en handtekening.
- E-mailbevestiging en retourherinnering.
- Automatische verwijdertaak voor verlopen ID-documenten.
- MySQL-driver voor grotere gelijktijdige bezetting.
