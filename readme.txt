=== WP Cookie Consent ===
Contributors: adventistai
Tags: gdpr, cookies, consent, privacy, lithuanian
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.4
Stable tag: 1.0.13
License: GPLv2 or later

Neįkyrus GDPR slapukų sutikimas su automatiniu slapukų skenavimu. Sąsaja lietuvių, anglų ir rusų kalbomis.

== Ką daro ==

* Pagal nutylėjimą neįrašo jokio neprivalomo slapuko. Net pats sutikimas saugomas naršyklės vietinėje saugykloje, ne slapuke.
* Blokuoja analitikos ir rinkodaros scenarijus bei įterptą turinį (YouTube, Vimeo, Facebook, Google Maps, Spotify, SoundCloud), kol lankytojas nesutinka.
* Ta pati HTML atsakymo versija tinka visiems lankytojams, todėl puslapių podėlis (WP Rocket, LiteSpeed, Cloudflare) veikia įprastai.
* Reguliariai nuskenuoja svetainę ir pats papildo slapukų sąrašą. Radus naują neprivalomą slapuką, sutikimo klausiama iš naujo.
* Sukuria slapukų politikos puslapį su pilna lentele: pavadinimas, tiekėjas, paskirtis, galiojimas.
* Kaupia sutikimo įrodymus. IP adresas ir naršyklė saugomi tik SHA-256 maišos pavidalu.
* „Google Consent Mode v2“ signalai (denied pagal nutylėjimą, update po pasirinkimo).

== Diegimas ==

1. Nukopijuokite aplanką į /wp-content/plugins/ arba įkelkite ZIP per Įskiepiai → Pridėti naują → Įkelti.
2. Aktyvuokite. Slapukų politikos puslapis sukuriamas automatiškai.
3. Meniu „Slapukai“ → „Nustatymai“ pasirinkite padėtį, spalvą ir numatytąją kalbą.
4. „Slapukai ir skenavimas“ → „Skenuoti dabar“, tada peržiūrėkite geltonai pažymėtus nepriskirtus slapukus.
5. Po didesnių pakeitimų paleiskite „Gilų skenavimą naršyklėje“ ir spustelėkite per kelis puslapius.

== Trumpiniai ==

* `[aicc_cookie_policy]` – visas slapukų sąrašas.
* `[aicc_cookie_policy lang="ru"]` – sąrašas konkrečia kalba.
* `[aicc_cookie_settings]` – mygtukas „Keisti pasirinkimą“.
* Nuoroda `#aicc-settings` meniu ar poraštėje atidaro nustatymų langą.

== Kabliukai kūrėjams ==

* `aicc_services` – filtras naujoms paslaugoms ir jų slapukams registruoti.
* `aicc_should_block` – filtras blokavimui išjungti konkrečiuose puslapiuose.
* `aicc_new_cookies_found` – veiksmas, kai skeneris randa naujų slapukų.
* `aicc:consent` – naršyklės įvykis su lankytojo pasirinkimu.

== GitHub atnaujinimas ==

Naujinimai tikrinami naujausioje ne bandomojoje GitHub leidimo versijoje: https://github.com/kiritoshiro/wp-cookie-consent/releases. Saugykla vieša, todėl tokeno nereikia. Neprivalomas tik skaitymui skirtas GitHub fine-grained tokenas padidina GitHub API užklausų limitą (ir būtų reikalingas, jei saugykla vėl taptų privati). Jį galima nustatyti wp-config.php faile prieš eilutę „That’s all, stop editing“:

    define( 'WP_COOKIE_CONSENT_GITHUB_TOKEN', 'github_pat_...' );

Tokenui suteikite tik šios saugyklos Contents: Read leidimą ir neįkelkite jo į saugyklą. Kai GitHub leidimas paskelbiamas, veiksmas prideda wp-cookie-consent.zip diegimo paketą.

== Keitimų istorija ==

= 1.0.13 =
* Atnaujinimai: pakartotinai patikrinus atnaujinimus skiltyje Pultas → Atnaujinimai (force-check), naujas GitHub leidimas randamas iš karto, nebelaukiant, kol baigsis talpyklos laikas.

= 1.0.12 =
* Kalbų mygtukai sutikimo lange išlaiko savo išvaizdą, net kai tema nuspalvina visus mygtukus: pasirinkta kalba rodoma baltomis raidėmis žaliame fone (anksčiau buvo žalia ant temos mėlynos ir beveik nematoma), o mygtukai didesni, patogesni liesti.

= 1.0.11 =
* Perkeltas viešas pavadinimas ir saugykla į WP Cookie Consent / wp-cookie-consent.
* Pridėti naujausio privataus GitHub leidimo tikrinimas ir autentifikuotas WordPress atnaujinimas.
* Pridėtas GitHub veiksmas, kuris prie leidimo prideda WordPress įskiepio ZIP paketą.

= 1.0.8 =
* Pataisyta kritinė paleidimo tvarka: valdymo scenarijus nebesibaigia prieš išvedant sutikimo lango HTML.
* Pridėtas atsarginis paleidimas po `DOMContentLoaded`, todėl veikia ir temose, kurios poraštės elementus išveda neįprasta tvarka.
* Įskiepio valdymo scenarijai pažymėti taip, kad jų neatidėtų spartinimo ir automatinio slapukų blokavimo įrankiai.

= 1.0.7 =
* Pataisyta: po pakartotinio įdiegimo naršyklėje likęs senas pasirinkimas nebeslopina sutikimo klausimo.
* Atnaujinant iš ankstesnės versijos sutikimo revizija vieną kartą pakeičiama, todėl klausimas parodomas automatiškai.
* Laikinas juostos paslėpimas susiejamas su konkrečia revizija ir nebeslepia naujo klausimo tame pačiame naršyklės seanse.

= 1.0.6 =
* Saugumas: REST užklausoms pridėtas griežtas tipų, dydžio ir kilmės tikrinimas.
* Saugumas: giluminio skenavimo rezultatai rodomi nekuriant HTML iš nepatikimų reikšmių.
* Patikimumas: pataisytas skenerio prieigos rakto raidžių dydžio neatitikimas ir naudojamos nuo SSRF apsaugotos užklausos.
* Patikimumas: CSV eksportas vykdomas dalimis, o suplanuotos užduotys visiškai išvalomos išjungiant ar šalinant įskiepį.
* Suderinamumas: tinkamai blokuojami ir atkuriami scenarijai su ne kabutėse įrašytais atributais bei `type="module"`.

= 1.0.5 =
* Saugumas: viešas sutikimų registravimo galinis taškas apribotas (15 įrašų per valandą iš vieno kliento).
* Sutikimų įrašai automatiškai ištrinami po nustatyto laikotarpio (numatyta 24 mėn.).
* CSV eksportas apsaugotas nuo formulių injekcijos.

= 1.0.4 =
* Po lankytojo pasirinkimo ekrane nebelieka nieko – mygtukas kampe pagal nutylėjimą rodomas tik kol neatsakyta.

= 1.0.3 =
* Pataisyta: juosta neišnykdavo paspaudus mygtuką, jei tema turi `section { display: block }` taisyklę.

= 1.0.2 =
* Juosta pasitraukia, jei lankytojas jos neliečia (sutikimas nesuteikiamas, klausiama kitą kartą).
* Nauja: iš anksto pažymėtos kategorijos ir neblokuojamų paslaugų sąrašas.
* Nauja: „YouTube“ privatumo režimas (youtube-nocookie.com).

= 1.0.1 =
* Pataisyta: „WooCommerce“ slapukai būdavo įtraukiami net ir tada, kai įskiepis neįdiegtas.
* Skenavimo metu automatiškai pašalinami slapukai, priklausantys neaktyviems įskiepiams.

= 1.0.0 =
* Pirmas leidimas.
