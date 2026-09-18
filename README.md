# Aerts Action Bike — interne verhuurmodule

PHP 8.2-module voor interne fietsverhuur, planning, betalingen, contractopmaak en elektronische ondertekening.

## Functionaliteit

- Horizontale agenda met één rij per fiets en duidelijke kleuren per verhuurstatus.
- Planning toont actieve fietsen, fietsen in onderhoud en inactieve fietsen.
- Fietsen in onderhoud of met status inactief kunnen niet worden ingepland.
- Live beschikbaarheidscontrole bij het kiezen van de huurperiode.
- Eén verhuurdossier kan meerdere fietsen tegelijk bevatten.
- Eén gezamenlijk contract vermeldt alle fietsen, framenummers, maten en dagprijzen.
- Betalingslog met bedrag, Bancontact of cash, medewerker en tijdstip.
- Automatische status: nog niet betaald, deels betaald of volledig afgerekend.
- Server-side blokkering van overlappende reservaties voor elke geselecteerde fiets.
- Fietsbeheer met unieke interne code, uniek framenummer, framemaat, status en foto.
- Gebruikersprofielen met beheerder- en medewerkerrol.
- Naam-, datum- en tijdstempel bij aanmaak en afsluiting van een huur.
- Publieke ondertekenpagina met handtekeningvak en beveiligde toegangstoken.
- Contracthash, ondertekenmoment, IP-adres en user-agent als bewijsgegevens.
- Ondertekend contract als PDF wanneer Dompdf geïnstalleerd is.
- Contractkopie via PHPMailer; in testmodus wordt een mailvoorbeeld lokaal opgeslagen.
- Optionele private documentupload buiten de publieke webmap.

## Vereisten

- PHP 8.2 of hoger
- PHP-extensies `pdo_sqlite`, `fileinfo`, `dom` en `mbstring`
- Composer
- Schrijfrechten op `storage/`
- HTTPS in productie

Lokaal gebruikt `composer serve` de map `public/` als document root. Voor Combell kan de volledige projectmap onder `/www/huur-module/` staan: de root-entrypoints laden de echte bestanden uit `public/` en de root-`.htaccess` routeert `/assets/...` naar `public/assets/...` en blokkeert private projectmappen.

## Combell File Manager deployment

Doelmap:

```text
/www/huur-module/
```

De repository bevat root-entrypoints voor `index.php`, `planning.php`, `bikes.php`, `reservation-new.php`, `reservation.php`, `contract.php`, `sign.php`, `users.php`, `bike-photo.php`, `api-bike-availability.php` en `reservation-stamp.php`.

Hierdoor zijn de normale productie-URL's:

```text
https://www.aertsactionbike.cc/huur-module/
https://www.aertsactionbike.cc/huur-module/planning.php
https://www.aertsactionbike.cc/huur-module/bikes.php
```

Directe links onder `/public/*.php` worden door `.htaccess` terug naar de root-route gestuurd. `public/assets/` blijft de fysieke assetmap; `/assets/...` wordt intern daarheen gerouteerd.

Bij een File Manager-update mag je de code uit de lokale projectmap overschrijven, maar **niet blind de volledige lokale map over productie zetten**. Behoud altijd de serverversies van:

```text
.env
storage/database.sqlite
storage/private/
storage/logs/
storage/backups/
```

De lokale `.env` kan localhost-instellingen bevatten en de lokale database kan testdata bevatten. Upload `.git/` en lokale backups niet naar productie.

## Bestaande installatie bijwerken

Maak eerst een databaseback-up en voer daarna uit:

```bash
mkdir -p ~/backups/huur-module
cp storage/database.sqlite ~/backups/huur-module/database-$(date +%Y%m%d-%H%M%S).sqlite

git pull origin agent/initial-rental-module
composer install --no-dev --optimize-autoloader
php bin/setup.php
chmod -R 775 storage
```

`php bin/setup.php`:

- verwijdert geen bestaande reservaties, fietsen of gebruikers;
- maakt `reservation_bikes` en `payment_logs` aan;
- koppelt elke bestaande reservatie automatisch aan de reeds opgeslagen fiets;
- voegt ontbrekende afsluitstempels en fietsvelden toe;
- maakt de private fotomap aan.

## Belangrijkste routes

- `planning.php`
- `reservation-new.php`
- `reservation.php?id=1`
- `bikes.php`
- `users.php` — alleen beheerders
- `contract.php?reservation_id=1`
- `sign.php?token=...`

Oude links via `index.php?route=...` worden automatisch doorgestuurd.

## Meerdere fietsen reserveren

1. Open **Nieuwe verhuur**.
2. Stel eerst start- en eindmoment in.
3. De selectielijst toont per fiets **BESCHIKBAAR**, **IN ONDERHOUD**, **INACTIEF** of **AL GERESERVEERD**.
4. Selecteer meerdere fietsen met Ctrl op Windows of Command op Mac.
5. Sla het dossier op.
6. De module maakt één gezamenlijk contract voor alle geselecteerde fietsen.

De controle gebeurt zowel in de browser als opnieuw op de server bij het opslaan.

## Betalingslog

Bij de aanmaak kan onmiddellijk een betaling via Bancontact of cash worden geregistreerd. Nadien kunnen bijkomende betalingen vanuit de verhuurfiche worden toegevoegd.

Elke logregel bewaart:

- bedrag;
- betaalwijze;
- datum en uur;
- medewerker;
- optionele notitie.

Betalingen worden niet overschreven. Het openstaande saldo wordt berekend als totaalprijs min de som van alle logregels.

## Planning en kleurlegende

De planning bevat een vaste legende voor:

- Gereserveerd;
- Bevestigd;
- Afgehaald;
- Teruggebracht;
- Beschikbare fiets;
- Fiets in onderhoud;
- Inactieve fiets.

Onderhoud en inactief worden als geblokkeerde rijen weergegeven. Bestaande reservaties blijven zichtbaar, maar nieuwe lege tijdvakken zijn niet aanklikbaar.

## Fietsidentificatie

Per fiets kunnen worden bijgehouden:

- interne code;
- naam en model;
- uniek framenummer;
- categorie en framemaat;
- dagprijs en status;
- JPG-, PNG- of WebP-afbeelding.

```dotenv
BIKE_IMAGE_MAX_MB=8
```

Afbeeldingen worden opgeslagen onder `storage/private/bikes/` en alleen via een beveiligde route aan ingelogde medewerkers getoond.

## E-mail

Testmodus:

```dotenv
MAIL_TRANSPORT=log
```

Mailvoorbeelden worden opgeslagen onder `storage/private/mail/`.

Voor echte verzending is een ondersteunde SMTP- of OAuth-configuratie nodig. Plaats wachtwoorden en sleutels uitsluitend in `.env` en nooit in GitHub.

## Controle na update

```bash
php -l app/repositories.php
php -l app/contracts_v2.php
php -l public/planning.php
php -l public/reservation-new.php
php -l public/reservation.php
php -l public/api-bike-availability.php
php bin/setup.php
```

Open daarna de planning en test één dossier met minstens twee fietsen en één deelbetaling.

## Juridisch en privacy

De contracttekst is een operationeel model en moet vóór definitieve productie juridisch worden nagekeken. Maak een kopie van een identiteitskaart niet verplicht zonder concrete wettelijke basis. Gebruik waar mogelijk visuele identificatie en verwerk alleen noodzakelijke gegevens.
