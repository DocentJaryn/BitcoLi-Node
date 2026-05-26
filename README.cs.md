# BitcoLi

Decentralizovaný Lightning „custodial" uzel pro malé skupiny lidí, kteří si důvěřují.

![Licence: AGPLv3](https://img.shields.io/badge/licence-AGPLv3-blue)

---

## 🧠 Myšlenka

Současné regulace (např. Travel Rule, MiCA, DORA, ...) prakticky znemožňují provoz malých Lightning služeb.

Výsledkem je:
- centralizace
- větší problém při selhání jedné entity

BitcoLi jde opačným směrem:

👉 mnoho malých uzlů  
👉 více peněženek v jedné aplikaci - jedna je offline, platím/přijímám do jiné  
👉 malé zůstatky („pár piv")  
👉 důvěra mezi lidmi, které znám  

---

## ⚙️ Jak to funguje

- každý uživatel/uzel má svůj klíč (ed25519) odvozený ze seedu
- veřejný klíč slouží jako identifikátor
- klient své klíče derivuje pro každý uzel zvlášť => klient je na každém uzlu identifikován jiným klíčem
- každý uzel funguje jako samostatná peněženka s vlastní účetní knihou
- využívat uzel může pouze klient, který obdrží pozvánku (QR kód/connection string)
- klient provede handshake s uzlem, kde se vzájemně ověří totožnost a pomocí x25519 si vymění klíče (chacha20_poly1305) pro další komunikaci
- každá operace je kryptograficky podepsaná:
  - klient podepisuje požadavky na platbu
  - uzel podepisuje vydané faktury a jejich stav

👉 Nelze popřít:
- „tuhle platbu jsem neposlal"
- „tahle faktura neexistuje"

---

## 🚀 Instalace

Klientská aplikace je zatím dostupná jen jako otevřená testovací verze v Google Play na adrese https://play.google.com/store/apps/details?id=com.bitcoli.client

Serverový plugin pro Umbrel je dostupný přes Community App Store na adrese https://github.com/DocentJaryn/UmbrelStore

Instalace automaticky zajistí:
- napojení na LND
- Tor konfiguraci
- zálohování

👉 žádná složitá konfigurace

---

## 👥 Skupiny a úrovně důvěry

Uživatelé jsou rozděleni do úrovní důvěry, např.:

- nízká důvěra (např. pokud pravidelně překračuje povolený zůstatek)
- nováček
- známý
- kamarád
- rodina

Každá úroveň má:
- max. zůstatek, při jehož překročení nebude umožněno vystavit další fakturu
- poplatky (plán, zatím bez poplatků)

---

## 🔄 Odolnost

- transakce se automaticky zálohují v šifrované podobě (chacha20_poly1305, klíč derivován ze seedu pro každou zálohu zvlášť) na vybrané ostatní BitcoLi uzly
- při pádu uzlu: po zadání seedu a alespoň jedné adresy spřáteleného BitcoLi uzlu se zálohou se obnoví poslední známý stav transakcí
- při ztrátě zálohy: klient předloží podepsané transakce jako důkaz, uzel ověří svůj podpis a bude je muset zařadit do historie (plán) — pokud tak neučiní, klientovi se zobrazí varování, že uzel podvádí
- při ztrátě seedu: závazky se řeší mimo systém (real-world)

---

## 🌐 Síť

- komunikace probíhá primárně přes Tor (.onion)
- podpora Toru v klientské app je zajištěna pomocí aplikace Orbot; v případě selhání se použije Tor proxy na serveru BitcoLi
- defaultně žádné port forwardingy
- žádná veřejná IP není potřeba, ale lze použít i clearnet na portu 27609

---

## 🔐 Proof of Consequences

Tento systém není založen na právních smlouvách ani regulaci, ale na reálných vztazích mezi lidmi.

> „Podvedeš mě? Poneseš následky."  
> Provozovatel uzlu nikdy nebude spravovat tolik, aby se mu vyplatilo utéct a vyměnit dlouhodobě budované vztahy za pár Satů.

---

## 🍺 Filosofie

👉 malé částky  
👉 známí lidé  
👉 žádná byrokracie  
👉 jednoduché nastavení  
👉 nezpochybnitelný zůstatek  
👉 jako zdroj pravdy se využívají podpisy transakcí  

---

## 🤝 Přispívání

Bugy a návrhy hlaste přes [GitHub Issues](../../issues). Pull requesty jsou vítány.

Tento projekt spotřebovává spoustu času — podpořit jej můžete i zasláním finanční podpory přes Lightning Network na [donate@bitcoli.com](lightning:donate@bitcoli.com)

![Donate QR](https://bitcoli.com/lnd/qrdeposit/donate)

---

## ⚖️ Licence

Tento projekt je licencován pod **AGPLv3**.

To znamená:
- můžeš ho používat a upravovat
- ale pokud ho provozuješ jako službu, musíš zveřejnit změny

---

## ⚠️ Disclaimer

Tento software není banka ani regulovaná služba.

Používáním tohoto software:
- svěřujete prostředky konkrétní osobě (provozovateli uzlu)
- důvěra je založena na reálném světě, osobních vztazích, ne na zákonech a regulacích

Autor neposkytuje finanční služby, nenese odpovědnost za ztrátu prostředků a dodává pouze software.

👉 Neukládejte více, než jste ochotni ztratit. Používejte na vlastní riziko.
