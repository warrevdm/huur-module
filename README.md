# Aerts Action Bike — interne verhuurmodule

PHP 8.2-module voor interne fietsverhuur, planning, contractopmaak, elektronische ondertekening en contractmail.

## Functionaliteit

- Horizontale agenda met één rij per fiets en verhuurblokken over meerdere dagen.
- Reservaties met klantgegevens, afhaal- en retourmoment, prijs, notities en status.
- Server-side blokkering van overlappende reservaties voor dezelfde fiets.
- Automatische huurovereenkomst na het opslaan van een reservatie.
- Publieke ondertekenpagina met handtekeningvak, naam, akkoordcheckbox en een willekeurige beveiligingstoken.
- Bewijsregistratie met contracthash, ondertekenmoment, IP-adres en user-agent.
- Ondertekend contract als PDF wanneer Dompdf geïnstalleerd is.
- Contractkopie via SMTP met PHPMailer; in lokale testmodus wordt een mailvoorbeeld opgeslagen.
- Contractstatus en herverzending vanuit de reservatiefiche.
- Optionele private documentupload buiten de publieke webmap.

## Vereisten

- PHP 8.2 of hoger
- PHP-extensies `pdo_sqlite`, `fileinfo`, `dom` en `mbstring`
- Composer
- Schrijfrechten op `storage/`
- HTTPS in productie
- De document root moet naar `public/` wijzen

## Bestaande lokale installatie bijwerken

```powershell
# Stop eerst de PHP-server met Ctrl+C
git pull origin agent/initial-rental-module
composer install
php bin/setup.php
php -S localhost:8080 -t public
```

`php bin/setup.php` verwijdert geen bestaande reservaties. Het maakt de nieuwe contracttabel aan wanneer die nog ontbreekt.

## Nieuwe installatie

```powershell
Copy-Item .env.example .env
composer install
php bin/setup.php
php -S localhost:8080 -t public
```

Open `http://localhost:8080`.

## Contractflow

1. Medewerker maakt een reservatie aan.
2. De module maakt automatisch een contract aan.
3. Medewerker controleert de inhoud en opent de ondertekenpagina.
4. De klant leest de voorwaarden, vult zijn of haar naam in en tekent op het scherm.
5. De module bewaart de handtekening, contractinhoud, hashes en technische bewijsgegevens.
6. De module maakt een PDF en mailt de exacte contractinhoud naar de klant.
7. Vanuit de reservatiefiche kan het contract opnieuw worden geopend, gedownload of gemaild.

## E-mail instellen

Lokaal staat de module standaard in testmodus:

```dotenv
MAIL_TRANSPORT=log
```

De e-mail wordt dan niet echt verzonden, maar opgeslagen onder:

```text
storage/private/mail/
```

Voor echte verzending:

```dotenv
MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.voorbeeld.be
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=gebruikersnaam
MAIL_PASSWORD=sterk-app-wachtwoord
MAIL_FROM_ADDRESS=info@aertsactionbike.be
MAIL_FROM_NAME="Aerts Action Bike"
```

Gebruik bij Microsoft 365 bij voorkeur een afzonderlijk SMTP-account of OAuth2/appconfiguratie volgens het interne IT-beleid. Plaats nooit mailwachtwoorden in Git.

## Contractvoorwaarden

De meegeleverde modeltekst gebruikt geen willekeurige vaste hoge boete. Ze voorziet:

- bij laattijdige teruggave: de daghuur per begonnen periode van 24 uur;
- bij schade: de aantoonbare herstelkost, met uitsluiting van normale slijtage;
- bij definitieve niet-teruggave: de aantoonbare actuele vervangingswaarde, rekening houdend met leeftijd en staat, plus redelijke bewezen recuperatiekosten;
- een gelijkwaardige oplossing voor de klant wanneer Aerts Action Bike de afgesproken fiets niet kan leveren.

Laat de definitieve tekst, bedrijfsgegevens en operationele bedragen vóór productie nakijken door een Belgische jurist of sectorfederatie.

## Elektronische handtekening

De canvas-handtekening is een gewone elektronische handtekening met aanvullende bewijsgegevens. Ze is niet automatisch een gekwalificeerde elektronische handtekening. Voor contracten met een hoger bewijsrisico kan later itsme, eID of een gekwalificeerde ondertekenprovider worden geïntegreerd.

## Identiteitsgegevens

Maak een kopie van een identiteitskaart niet verplicht zonder concrete wettelijke basis. Gebruik waar mogelijk een visuele controle en verwerk alleen noodzakelijke gegevens. De bestaande upload is optioneel en moet vóór productie worden afgestemd met de privacyverantwoordelijke.

## Belangrijkste routes

- `index.php?route=planning`
- `index.php?route=reservation-new`
- `index.php?route=reservation-view&id=1`
- `contract.php?reservation_id=1`
- `sign.php?token=...`
