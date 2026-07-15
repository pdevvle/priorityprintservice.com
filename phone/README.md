# Priority Print Service — Phone / Voicemail Service

A Twilio phone line that answers calls, tries your real phone first, takes a
voicemail if you don't pick up, transcribes it, has **Claude** summarize it, and
drops the whole thing into **Missive** as a new conversation.

> **Security note:** real phone numbers are intentionally kept **out of this
> repo**. Put your Twilio line and forwarding number only in the Twilio Console
> (the TwiML Bin) and in Make — both private to your accounts. This repo uses
> placeholders.

```
Caller dials your Twilio line
        │
        ▼
  Twilio TwiML Bin  ──►  Rings your phone (forwarding number, ~20s)
        │                        │
        │                  you answer ──► normal call, done
        │                        │
        │                  no answer
        ▼                        ▼
  Greeting plays  ──►  Caller records a voicemail
        │
        ▼
  Recording saved on Twilio (the master copy)
        │
        ▼  (Make polls every ~15 min via "Watch Recordings")
  Make scenario: "PPS: Phone — Voicemail → Whisper → Claude → Missive"
        │
        ├─ Twilio · Get a Call ......... caller's number, time, duration
        ├─ Twilio · Download Recording . the MP3 audio
        ├─ OpenAI · Transcription ...... gpt-4o-transcribe (Whisper family)
        ├─ Claude · Sonnet 5 ........... summary, callback #, urgency, draft reply
        └─ Missive · Create a Post ..... new conversation in the shared inbox
```

## Where the voicemail is stored

The **audio recording lives on Twilio** (encrypted at rest), retrievable by URL
or the Recordings API until you delete it. Storage cost is ~$0.0005/min per
month — negligible. Day to day you live in **Missive**: each voicemail becomes a
conversation containing the caller's number, time, duration, the transcript, the
Claude summary/draft reply, and a **playable link** back to the Twilio recording.

> The link is the Twilio media URL (`…/Recordings/RE….mp3`), which is publicly
> playable by default. If you later enable Twilio's "require auth for media"
> setting, the link will prompt for credentials — at that point switch to
> attaching/archiving the MP3 (see *Future enhancements*).

## The two halves

### 1. Twilio (you set this up in the Console — one time)

The realtime call handling is a **TwiML Bin** so it never depends on any other
service being online. The XML is in [`twiml-voicemail.xml`](./twiml-voicemail.xml).

**Steps:**

1. Log in to the [Twilio Console](https://console.twilio.com).
2. Go to **Develop → TwiML Bins** (or search "TwiML Bins") → **Create new TwiML Bin**.
   - **Friendly name:** `PPS Voicemail`
   - **TwiML:** paste the contents of `twiml-voicemail.xml`, then **replace the
     placeholder `+10000000000` with your real forwarding number** (this Bin is
     private to your Twilio account).
   - **Save.**
3. Go to **Phone Numbers → Manage → Active numbers →** *your Twilio line*.
   - Under **Voice Configuration → A call comes in**, choose **TwiML Bin** and
     select **PPS Voicemail**.
   - **Save.**
4. That's it. Calls now ring your phone first, then take a voicemail.

> **Confirm the account:** the Make scenario reads recordings from the Twilio
> account connected to Make (`AC3f99…`). Make sure your Twilio line is on that
> same account. If it's on a different Twilio account/subaccount, either move it
> or reconnect Make to that account, otherwise the recordings won't be seen.

To change the **forward-to number** or the **greeting wording**, just edit the
TwiML Bin — nothing else needs to change.

To go **straight to voicemail** (no ring-first), delete the `<Dial>…</Dial>`
block from the Bin.

### 2. Make.com (built for you)

Scenario: **`PPS: Phone — Voicemail → Whisper → Claude → Missive`**
(**scenario id `4828757`**, Team "My Team" 94685, org 237037). It has already
been created in Make and is currently **inactive** — activate it after testing.
The full, importable definition is in
[`make-scenario.blueprint.json`](./make-scenario.blueprint.json) (use it to
re-import or recreate the scenario if needed).

| Step | Module | Connection | Notes |
|------|--------|-----------|-------|
| 1 | Twilio · **Watch Recordings** (trigger) | `My Twilio connection` (350124) | Polls the account for new recordings, 2 per cycle |
| 2 | Twilio · **Get a Call** | 350124 | Looks up the call by `call_sid` to get the caller's number/time |
| 3 | Twilio · **Download a Recording Media** | 350124 | Fetches the MP3 by recording SID |
| 4 | OpenAI · **Create a Transcription** | `My OpenAI connection` (4631426) | Model `gpt-4o-transcribe` |
| 5 | Anthropic · **Create a Prompt** | `My Anthropic Claude connection` (4822595) | Model `claude-sonnet-5`, 1024 max tokens |
| 6 | Missive · **Create a Post** | `office@priorityprintservice.com` (434289) | New conversation, added to Inbox |

**Schedule:** polls every **15 minutes** (`interval: 900`). Lower it for faster
delivery, or raise it to save operations. (At 15 min it's ~3k operations/month
of your 10k plan just for polling.)

## Activating & testing

1. **Finish the Twilio side** (steps above) so a recording can be produced.
2. In Make, open the scenario. Because "Watch Recordings" is a polling trigger,
   the **first** run asks where to start — choose **"from now"** so it doesn't
   ingest old recordings.
3. **Leave a test voicemail:** call your Twilio line from another phone, let it
   ring past ~20s (don't answer your cell), and leave a short message.
4. In Make, click **Run once** on the scenario to process it immediately
   (instead of waiting for the 15-min poll).
5. Check **Missive** — a new conversation titled `📞 Voicemail — <number>`
   should appear with the summary, transcript, and a play link.
6. When it looks right, toggle the scenario **ON** (scheduling) so it runs
   automatically.

## Costs (rough, per voicemail)

- Twilio inbound + recording: a few cents.
- OpenAI transcription (`gpt-4o-transcribe`): ~$0.006/min.
- Claude Sonnet 5 summary: a fraction of a cent (short input/output).
- Missive: included in your plan.

So a typical voicemail costs a few cents all-in. Keep a little credit on the
[Anthropic console](https://console.anthropic.com) (Settings → Billing) for the
Claude calls.

## Troubleshooting

- **Nothing appears in Missive:** run the scenario manually (**Run once**) and
  read the bubble on each module. Most common: the recording is on a Twilio
  account that isn't the one connected to Make.
- **Transcript is empty:** the caller left silence/hung up — Claude will note
  it and mark urgency Low.
- **Play link asks for a password:** Twilio media auth is enabled on the
  account; switch to attaching/archiving the MP3 (below).
- **"Your TwiML syntax is invalid":** make sure you pasted the current
  `twiml-voicemail.xml` verbatim and that no decorative `--` runs sneaked into a
  comment (XML comments may not contain a double hyphen).

## Future enhancements (not built yet)

- **Business-hours routing** — ring your phone only Mon–Fri 9–5 (America/Denver),
  straight to voicemail otherwise. Needs a Twilio Studio Flow or a small
  Function instead of the static Bin.
- **SMS auto-reply** — text the caller back automatically after they leave a
  message (Twilio · Create a Message). Requires the number be SMS-capable.
- **Post to a Missive team inbox** and/or **auto-assign/label** so it lands in
  the right queue.
- **Own the audio outside Twilio** — attach the MP3 into the Missive post and/or
  archive a copy to Google Drive (`office@priorityprintservice.com`).
- **Live AI receptionist** — instead of taking a message, have Claude *talk to
  the caller* in real time (Twilio Media Streams ↔ streaming voice ↔ Claude).
  A separate, larger project.
