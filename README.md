<p align="center"><img src="logo.svg?v=2" width="96" alt="dotmtickets"></p>

# dotmtickets — Biglietti QR per WooCommerce

Plugin WordPress che trasforma i prodotti WooCommerce in **biglietti con QR code**. Per ogni biglietto acquistato genera un PDF che parte allegato alla mail dell'ordine. All'ingresso gli addetti lo verificano dallo smartphone, con uno scanner che gira nel browser. Ogni ingresso viene registrato: non si entra due volte con lo stesso biglietto, né in giorni diversi da quelli validi.

Si installa e fa tutto da solo: crea le tabelle, il ruolo per gli addetti, lo stato d'ordine e le pagine di amministrazione. Non serve modificare il tema.

> *English summary: WordPress/WooCommerce plugin that turns products into QR code tickets. Each purchased unit gets a unique ticket; a PDF (one page per ticket) is attached to the order emails and downloadable from the order page. Staff check tickets at the entrance with a browser-based scanner (no app), with atomic check-ins per day and per number of people. Optional manual approval for free accreditations (press, trade), an admin dashboard with live stats and CSV export, configurable logo, band, sponsors, colour and texts. Strings in English with Italian translation included. MIT licence.*

## Funzionalità

- **Prodotto «Biglietto QR»**: una casella accanto a *Virtuale* / *Scaricabile* nei prodotti semplici. Per ogni prodotto si può indicare:
  - **giorni di validità** (es. una fiera su due giorni: un ingresso al giorno nelle date indicate); se lasciati vuoti, il biglietto vale per un solo ingresso;
  - **persone per biglietto** (es. 2 per un accredito valido per due persone);
  - **nome, data e luogo dell'evento**, se diversi da quelli generali.
- **Biglietti sempre allineati all'ordine**: vengono generati quando l'ordine risulta pagato, con un biglietto per ogni unità acquistata. Se l'ordine viene annullato, rimborsato in tutto o in parte, o la quantità scende, i biglietti in più vengono **annullati**, mai cancellati.
- **PDF dei biglietti**, una pagina per biglietto, con QR code, codice breve leggibile, dati dell'evento, prezzo, ordine, acquirente, istruzioni e regole d'uso, più i loghi degli sponsor se ci sono. Viene allegato alle mail «Ordine in lavorazione», «Ordine completato» e «Dettagli ordine». Si scarica anche dalla pagina dell'ordine, nel sito e in *Il mio account*.
- **Scanner per gli addetti** su `/?dmt_scanner=1`: usa la fotocamera dello smartphone e non richiede app. Si può anche inserire il codice breve a mano (es. `893F FCB7`). L'esito è a tutto schermo e colorato: **VALIDO**, **GIÀ ENTRATO OGGI**, **NON VALIDO OGGI**, **ANNULLATO**. Ogni ingresso è registrato in modo atomico, quindi due addetti che scansionano lo stesso biglietto nello stesso istante non fanno entrare più persone del dovuto.
- **Ruolo «Addetto ingressi»**: può solo verificare i biglietti, non vede l'amministrazione e dopo il login finisce direttamente sullo scanner. Amministratori e shop manager possono comunque verificare.
- **Conferma manuale** (facoltativa, per prodotto), per esempio per accrediti gratuiti a stampa od operatori:
  - l'ordine resta in stato **«Da confermare»** e il richiedente riceve una mail di «richiesta ricevuta»;
  - dalla pagina *Biglietti* si **conferma**, e allora parte la mail con il biglietto, oppure si **rifiuta**, e l'ordine viene annullato;
  - al checkout si possono chiedere il **tipo di attività** (elenco configurabile) e il **codice fiscale / partita IVA**, facoltativo oppure obbligatorio. La verifica italiana dei codici è attivabile;
  - funziona sia con il checkout **a blocchi** sia con quello classico.
- **Pagina WooCommerce → Biglietti**:
  - richieste da confermare;
  - ingressi di oggi;
  - situazione per prodotto e per giorno, con barre di avanzamento;
  - ultimi ingressi;
  - elenco filtrabile di tutti i biglietti (per prodotto, stato, codice, ordine, nome, email);
  - **esportazione CSV**;
  - aggiornamento automatico ogni 30 secondi, attivabile.
- **Riquadro «Biglietti QR» nell'ordine**: elenco dei biglietti con il loro stato, gli ingressi registrati (annullabili se fatti per errore), il PDF da scaricare e l'azione «Genera / aggiorna biglietti QR».
- **Aspetto neutro**: grigi più un solo colore configurabile, usato per fascia, titoli, pulsanti e avviso nelle email. Il colore del testo sulla fascia (bianco o scuro) si sceglie da solo in base al colore.
- **Multilingua**: i testi del plugin sono in inglese, con la traduzione italiana inclusa (`languages/`). I testi del biglietto si possono riscrivere dalle impostazioni.
- Compatibile con **HPOS** (tabelle ordini di WooCommerce) e con il **checkout a blocchi**.

## Requisiti

- WordPress **6.5** o successivo
- WooCommerce **8.0** o successivo
- PHP **8.0** o successivo, con l'estensione **GD** (per le immagini nel PDF; senza GD il QR code viene comunque generato in SVG)
- HTTPS sul sito, perché i browser concedono la fotocamera allo scanner solo su connessioni sicure

Le librerie per il PDF e il QR code sono già incluse nel plugin: non serve Composer.

## Installazione

1. Scarica lo ZIP da GitHub (*Code → Download ZIP*).
2. Lo ZIP di GitHub si scompatta nella cartella **`wp_tickets-main`**: rinominala in **`dotmtickets`** e ricomprimila, così da avere `dotmtickets.zip` con dentro la cartella `dotmtickets/`.
3. In WordPress vai su *Plugin → Aggiungi nuovo → Carica plugin*, scegli `dotmtickets.zip` e attivalo.
   In alternativa copia la cartella `dotmtickets` in `wp-content/plugins/` e attivala da *Plugin*.

All'attivazione il plugin crea le tabelle, il ruolo «Addetto ingressi» e le impostazioni predefinite. WooCommerce deve essere già attivo.

## Configurazione

*WooCommerce → Biglietti → Impostazioni*:

| Sezione | Cosa si imposta |
|---|---|
| **Evento** | Nome, data (testo libero), luogo e riga a piè di pagina del biglietto. Il nome predefinito è quello del sito. |
| **Aspetto** | Colore; logo (se manca si usa l'icona del sito o il suo nome); immagine della fascia (circa 1840 × 300 px); loghi degli sponsor e titolo della loro sezione; formato carta A4 o Letter. |
| **Testi del biglietto** | Come usare il biglietto e regole d'uso (ingresso singolo, un ingresso al giorno, più persone). Se un campo resta vuoto vale il testo standard, tradotto nella lingua del sito. |
| **Conferma manuale** | Tipi di attività (uno per riga, vuoto = non chiesto) e loro etichetta; campo codice fiscale / P.IVA (non richiesto, facoltativo, obbligatorio), sua etichetta e verifica (nessuna oppure italiana). |
| **Disinstallazione** | «Elimina i dati»: se attiva, l'eliminazione del plugin cancella anche biglietti, ingressi, impostazioni e ruolo. |

Il pulsante **«Anteprima biglietto PDF»** genera un PDF di esempio con le impostazioni salvate.

## Dopo l'installazione

1. **Crea il biglietto**: in un prodotto semplice spunta *Biglietto QR* e, se servono, compila nella scheda *Generale* i giorni di validità, le persone per biglietto e i dati dell'evento. Per gli accrediti spunta anche *Richiede conferma*.
2. **Crea gli addetti**: in *Utenti → Aggiungi nuovo* scegli il ruolo **«Addetto ingressi»**. Dopo il login l'addetto arriva direttamente allo scanner.
3. **Prova un acquisto**: alla conferma del pagamento la mail al cliente contiene il PDF. Nell'ordine compare il riquadro *Biglietti QR*.
4. **All'ingresso**: gli addetti aprono `https://tuosito/?dmt_scanner=1` (link anche in *WooCommerce → Biglietti → Apri scanner*) e inquadrano il QR. Conviene salvare la pagina nella schermata home dello smartphone.

Il QR contiene l'indirizzo `https://tuosito/?dmt_ticket=<token>`. Se lo apre un cliente vede solo un messaggio («la verifica è riservata al personale»): i dati del biglietto li vedono solo gli addetti collegati.

## Personalizzazione

- **Template del PDF**: copia `templates/ticket-pdf.php` in `wp-content/themes/<tuo-tema>/dotmtickets/ticket-pdf.php` e modificalo lì. In cima al file trovi l'elenco delle variabili disponibili. Il PDF è generato con dompdf (CSS 2.1, impaginazione con tabelle, misure in mm).
- **Testi**: i testi del biglietto si cambiano dalle impostazioni; tutti gli altri con un file di traduzione (`languages/dotmtickets.pot`, ad esempio con Loco Translate o Poedit).
- **Filtri**:

  | Filtro | Uso |
  |---|---|
  | `dotmtickets_email_ids` | Email WooCommerce a cui allegare il PDF (predefinite `customer_processing_order`, `customer_completed_order`, `customer_invoice`). |
  | `dotmtickets_pdf_template` | Percorso del template PDF. |
  | `dotmtickets_pdf_vars` | Tutte le variabili passate al template (colori, testi, logo…). |
  | `dotmtickets_pdf_tickets` | Dati dei biglietti di un ordine prima della generazione del PDF. |
  | `dotmtickets_pdf_sponsors` | Loghi degli sponsor (`src`, `w`, `h` in mm). |

- **Azioni**: `dotmtickets_order_synced( $order, $created )` dopo l'allineamento dei biglietti di un ordine; `dotmtickets_checked_in( $ticket, $people, $order )` dopo ogni ingresso registrato.

## Struttura

```
dotmtickets/
├── dotmtickets.php                        intestazione del plugin, caricamento, compatibilità HPOS/blocchi
├── uninstall.php                          pulizia alla disinstallazione (solo se «Elimina i dati» è attiva)
├── includes/
│   ├── class-dotm-tickets.php             biglietti: generazione, sincronizzazione con l'ordine, ingressi
│   ├── class-dotm-tickets-install.php     tabelle, ruolo, versione del DB e aggiornamenti
│   ├── class-dotm-tickets-settings.php    impostazioni
│   ├── class-dotm-tickets-product.php     campi del prodotto
│   ├── class-dotm-tickets-approval.php    conferma manuale, stato «Da confermare», campi del checkout
│   ├── class-dotm-tickets-admin-order.php riquadro nell'ordine
│   ├── class-dotm-tickets-dashboard.php   pagina WooCommerce → Biglietti ed export CSV
│   ├── class-dotm-tickets-pdf.php         generazione del PDF e anteprima
│   ├── class-dotm-tickets-emails.php      allegato alle email e download
│   └── class-dotm-tickets-scanner.php     scanner, pagina di verifica, ruolo addetti
├── templates/ticket-pdf.php               template del PDF (sovrascrivibile dal tema)
├── assets/                                script dell'amministrazione, html5-qrcode
├── languages/                             dotmtickets.pot, traduzione italiana (.po/.mo)
└── lib/                                   dompdf, php-qrcode, php-settings-container
```

**Database**: `{prefisso}dotmtickets_tickets` (un record per biglietto, con token univoco) e `{prefisso}dotmtickets_checkins` (un record per persona entrata, per giorno). Impostazioni nell'opzione `dotmtickets_settings`; dati dei prodotti nei meta `_dotmtickets_*`.

## Aggiornamento e disinstallazione

- **Aggiornare**: sostituisci la cartella `dotmtickets` con la nuova versione, oppure carica il nuovo ZIP e scegli «Sostituisci la versione installata». Al primo caricamento il plugin aggiorna da solo il database se serve (versione salvata in `dotmtickets_db_version`). Biglietti, ingressi e impostazioni restano. Non serve disattivarlo né reinstallarlo.
- **Disattivare** il plugin non cancella niente. Lo scanner e i link dei QR smettono di funzionare finché non viene riattivato.
- **Eliminare** il plugin cancella i dati solo se in *Impostazioni → Disinstallazione* è attiva «Elimina i dati». Gli ordini WooCommerce, comprese le note e i dati del checkout, non vengono mai toccati.

## Note legali

- Il plugin salva nell'ordine i dati chiesti al checkout per la conferma manuale (tipo di attività, codice fiscale / partita IVA) e registra quale addetto ha validato ogni ingresso, e quando. Indicalo nell'informativa privacy del sito.
- I PDF non vengono salvati sul server: si generano al momento e i file temporanei per l'allegato si cancellano a fine richiesta. Il link di download è protetto dalla chiave dell'ordine.

## Licenza

Rilasciato con licenza **MIT**: vedi il file [LICENSE](LICENSE).

Librerie incluse, ciascuna con la propria licenza:

- [dompdf](https://github.com/dompdf/dompdf), LGPL 2.1, e le sue dipendenze in `lib/dompdf/vendor`
- [php-qrcode](https://github.com/chillerlan/php-qrcode) e [php-settings-container](https://github.com/chillerlan/php-settings-container), MIT / Apache 2.0
- [html5-qrcode](https://github.com/mebjas/html5-qrcode), Apache 2.0

## Autore

Sviluppato da [dotm.it](https://dotm.it)
