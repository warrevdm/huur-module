# AAB eID Bridge — DIGIPASS 905

## Doel

De verhuurmodule draait op Combell en kan daarom niet rechtstreeks de USB-kaartlezer op een winkel-pc aanspreken. De lokale **AAB eID Bridge** draait uitsluitend op Windows en luistert alleen op `127.0.0.1:17895`.

De browserflow is:

1. medewerker opent **Nieuwe verhuur**;
2. klant steekt de Belgische eID in de OneSpan DIGIPASS 905;
3. medewerker klikt **eID uitlezen**;
4. de browser vraagt de lokale bridge om de kaart uit te lezen;
5. de bridge gebruikt de lokaal geïnstalleerde officiële Belgische eID Viewer-backend;
6. alleen naam, adres en einddatum van de kaartgeldigheid worden naar het formulier teruggestuurd.

De bridge verwerkt bewust **geen rijksregisternummer, foto, chipnummer, geboortedatum, geslacht of nationaliteit**.

## Vereisten op de winkel-pc

- Windows 10/11 x64;
- OneSpan DIGIPASS 905 aangesloten via USB;
- de reader werkt in Windows;
- officiële Belgische **eID Middleware** geïnstalleerd;
- officiële Belgische **eID Viewer** geïnstalleerd;
- voor bouwen vanuit broncode: .NET 8 SDK.

De bridge zoekt standaard naar:

```text
C:\Program Files\Belgium Identity Card\EidViewer\eIDViewerBackend.dll
```

Een afwijkend pad kan worden ingesteld met de omgevingsvariabele `AAB_EID_BACKEND_DLL`.

## Installeren vanuit de repository

Open PowerShell in de repository en voer uit:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\tools\eid-bridge\install.ps1
```

Het script:

- controleert of de Belgische eID Viewer-backend aanwezig is;
- bouwt een self-contained Windows x64 executable;
- installeert die onder `%LOCALAPPDATA%\AertsActionBike\EidBridge`;
- maakt standaard een opstartsnelkoppeling;
- start de bridge geminimaliseerd;
- voert een lokale health-check uit.

Zonder automatische start:

```powershell
.\tools\eid-bridge\install.ps1 -NoStartup
```

## Bridge testen

```powershell
Invoke-RestMethod http://127.0.0.1:17895/v1/health
```

Verwacht ongeveer:

```text
ok            : True
service       : AAB eID Bridge
backendLoaded : True
cardPresent   : False
readers       : {DIGIPASS ...}
```

Steek daarna een eID in de reader:

```powershell
Invoke-RestMethod 'http://127.0.0.1:17895/v1/read?timeout=12000'
```

## Browser

De productiepagina `https://warrevandermaat.be` is als toegestane origin opgenomen. De bridge accepteert geen externe netwerkverbindingen omdat hij alleen aan `127.0.0.1` bindt.

Moderne browsers kunnen bij de eerste toegang tot een lokale/loopbackdienst een toestemming voor lokaal netwerk of loopback tonen. Kies **Toestaan** op de vertrouwde Aerts Action Bike winkel-pc.

## Extra toegestane origins

Standaard:

```text
https://warrevandermaat.be
https://www.warrevandermaat.be
http://localhost:8080
http://127.0.0.1:8080
```

Voor een andere omgeving kan vóór het starten worden ingesteld:

```powershell
$env:AAB_EID_ALLOWED_ORIGINS='https://warrevandermaat.be;https://andere-host.example'
```

## Andere poort

Standaardpoort: `17895`.

```powershell
$env:AAB_EID_BRIDGE_PORT='17895'
```

Wanneer de poort wordt gewijzigd, moet ook `public/assets/eid-bridge.js` en de `connect-src` CSP in `app/bootstrap.php` worden aangepast.

## Probleemoplossing

### Geen kaartlezer gevonden

1. controleer USB;
2. open de officiële Belgische eID Viewer;
3. controleer of de DIGIPASS 905 daar zichtbaar is;
4. sluit de Viewer indien hij de reader exclusief vasthoudt;
5. herstart `AAB-eID-Bridge.exe`.

### Bridge niet bereikbaar vanuit Chrome/Edge

1. controleer `http://127.0.0.1:17895/v1/health` in PowerShell;
2. herlaad de verhuurpagina;
3. sta lokale/loopback-netwerktoegang toe wanneer de browser dit vraagt;
4. controleer dat de pagina via HTTPS op `warrevandermaat.be` geopend is.

### Backend DLL niet gevonden

Installeer of herstel de officiële Belgische eID Viewer. De AAB bridge redistribueert de overheids-DLL niet zelf.

## Privacy

De bridge logt geen inhoud van identiteitsvelden naar de console en geeft alleen de gegevens terug die de verhuurflow nodig heeft. Het huidige doel is **sneller invullen**, niet het opslaan van een digitale kopie van de eID.
