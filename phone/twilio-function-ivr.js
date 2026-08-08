/**
 * Priority Print Service — inbound IVR (Twilio Function)
 * Number: (623) 267-9479
 *
 * Menu:
 *   9 = shipping partners  -> forward to 602 541 7395; no answer -> voicemail (text 602 541 7395)
 *   1 = sales              -> IN HOURS: ring 623 695 5209 (12s) -> no answer -> voicemail
 *                             AFTER HOURS: straight to after-hours voicemail
 *                             either way -> text 623 695 5209
 *   2 = operations/reorder -> reorder message + voicemail -> text 602 541 7395
 *
 * Transcription is Twilio's built-in (async): each <Record> sets a transcribeCallback
 * that re-enters this Function at step=notify and sends the SMS with transcript +
 * caller number + a link to the recording.
 *
 * SETUP
 *   1. Twilio Console -> Functions & Assets -> Services -> Create service "pps-ivr".
 *   2. Add a Function with the path  /ivr  and paste this file. Deploy.
 *      (Make sure the Function is set to PUBLIC visibility so Twilio can call the
 *       transcribeCallback/action URLs.)
 *   3. Phone Numbers -> (623) 267-9479 -> Voice -> "A call comes in" ->
 *      Function -> Service pps-ivr -> Function /ivr -> Save.
 *   4. SMS_FROM below must be an SMS-capable / 10DLC-registered number on THIS account.
 *
 * NOTES
 *   - Arizona has no DST, so it is UTC-7 year round; business hours = 9:00-17:59 AZ,
 *     Monday through Friday. Weekends and outside those hours -> after-hours voicemail.
 *   - Built-in transcription is US-English, up to 2 min, ~$0.05/min. For higher accuracy
 *     you'd route recordings through the Whisper/Make pipeline instead (option B).
 */
exports.handler = function (context, event, callback) {
  const VoiceResponse = Twilio.twiml.VoiceResponse;
  const twiml = new VoiceResponse();
  const BASE = `https://${context.DOMAIN_NAME}/ivr`;
  const VOICE = { voice: 'Polly.Ruth-Generative' }; // swap to 'Polly.Ruth-Neural' if needed

  // --- config ---------------------------------------------------------------
  const FORWARD_SHIPPING = '+16025417395';
  const FORWARD_SALES = '+16236955209';
  const NOTIFY_SHIPPING = '+16025417395';
  const NOTIFY_SALES = '+16236955209';
  const NOTIFY_OPS = '+16025417395';
  const SMS_FROM = '+16232679479'; // (623) 267-9479 — must be SMS-capable on this account
  const DIAL_TIMEOUT = 12;         // short, so the callee's carrier voicemail doesn't grab it

  // Arizona = UTC-7 (no DST). Shift "now" into AZ local time to read hour + weekday.
  const azNow = new Date(Date.now() - 7 * 60 * 60 * 1000);
  const azHour = azNow.getUTCHours();     // 0-23 AZ local
  const azDay = azNow.getUTCDay();        // 0=Sun ... 6=Sat
  const isWeekday = azDay >= 1 && azDay <= 5;
  const businessHours = isWeekday && azHour >= 9 && azHour < 18;

  const step = event.step || 'menu';

  // --- greeting / menu ------------------------------------------------------
  if (step === 'menu') {
    const attempt = parseInt(event.attempt || '0', 10);
    if (attempt >= 2) {
      // No selection after two prompts — let them leave a general message.
      twiml.redirect({ method: 'POST' }, `${BASE}?step=vm&reason=ops`);
      return callback(null, twiml);
    }
    const gather = twiml.gather({
      numDigits: 1,
      timeout: 6,
      action: `${BASE}?step=route`,
      method: 'POST',
    });
    if (attempt === 0) {
      gather.say(VOICE,
        "Thank you for calling Priority Print Service. " +
        "If you're with one of our shipping partners, such as FedEx or U P S, press 9. " +
        "To speak to someone in sales, press 1. " +
        "For operations, or information on an existing order, press 2.");
    } else {
      gather.say(VOICE, "Please press 9 for shipping, 1 for sales, or 2 for operations.");
    }
    twiml.redirect({ method: 'POST' }, `${BASE}?step=menu&attempt=${attempt + 1}`);
    return callback(null, twiml);
  }

  // --- route the key press --------------------------------------------------
  if (step === 'route') {
    const digit = event.Digits;
    if (digit === '9') {
      const dial = twiml.dial({
        timeout: DIAL_TIMEOUT,
        answerOnBridge: true,
        action: `${BASE}?step=vm&reason=shipping`,
        method: 'POST',
      });
      dial.number(FORWARD_SHIPPING);
      return callback(null, twiml);
    }
    if (digit === '1') {
      if (businessHours) {
        const dial = twiml.dial({
          timeout: DIAL_TIMEOUT,
          answerOnBridge: true,
          action: `${BASE}?step=vm&reason=sales`,
          method: 'POST',
        });
        dial.number(FORWARD_SALES);
        return callback(null, twiml);
      }
      twiml.redirect({ method: 'POST' }, `${BASE}?step=vm&reason=sales_afterhours`);
      return callback(null, twiml);
    }
    if (digit === '2') {
      twiml.redirect({ method: 'POST' }, `${BASE}?step=vm&reason=ops`);
      return callback(null, twiml);
    }
    twiml.redirect({ method: 'POST' }, `${BASE}?step=menu&attempt=1`);
    return callback(null, twiml);
  }

  // --- voicemail ------------------------------------------------------------
  if (step === 'vm') {
    // If we arrived here as the <Dial> action and the call was actually answered,
    // there's nothing to record — just end.
    if (event.DialCallStatus && event.DialCallStatus === 'completed') {
      twiml.hangup();
      return callback(null, twiml);
    }

    const reason = event.reason || 'ops';
    let prompt = "Please leave a message after the tone.";
    let notify = NOTIFY_OPS;

    if (reason === 'shipping') {
      prompt = "Sorry, we weren't able to receive the call at this moment. However, if you leave a voicemail, we will transcribe it and notify the relevant staff.";
      notify = NOTIFY_SHIPPING;
    } else if (reason === 'sales') {
      prompt = "Sorry we missed you. Please leave a message for our sales team after the tone, and we'll get right back to you.";
      notify = NOTIFY_SALES;
    } else if (reason === 'sales_afterhours') {
      prompt = "You're calling outside our normal phone hours. Please leave a voice message and it will be transcribed and will notify the sales department. Or, for faster service, please email office at priority print service dot com.";
      notify = NOTIFY_SALES;
    } else if (reason === 'ops') {
      prompt = "To look up or request a reorder, visit priority print service dot com slash reorder, or look for the reorder button on our site. Otherwise, leave a message after the tone and we'll follow up.";
      notify = NOTIFY_OPS;
    }

    twiml.say(VOICE, prompt);
    const recordOpts = {
      maxLength: 120,
      playBeep: true,
      trim: 'trim-silence',
      action: `${BASE}?step=goodbye`,
      method: 'POST',
    };
    if (reason === 'ops') {
      // Option 2 -> Missive (not SMS). The recording lands on this Twilio account and
      // is picked up by the existing "voicemail -> Whisper -> Claude -> Missive" Make
      // scenario, which posts it to the shared inbox. No SMS here, and we skip Twilio's
      // built-in transcription since Make does its own (Whisper).
    } else {
      recordOpts.transcribe = true;
      recordOpts.transcribeCallback = `${BASE}?step=notify&notify=${encodeURIComponent(notify)}`;
    }
    twiml.record(recordOpts);
    twiml.redirect({ method: 'POST' }, `${BASE}?step=goodbye`);
    return callback(null, twiml);
  }

  if (step === 'goodbye') {
    twiml.say(VOICE, "Thank you. Goodbye.");
    twiml.hangup();
    return callback(null, twiml);
  }

  // --- transcription callback -> send SMS -----------------------------------
  if (step === 'notify') {
    const notify = event.notify;
    const caller = event.From || event.Caller || 'an unknown number';
    const transcript = (event.TranscriptionText && event.TranscriptionText.trim())
      ? event.TranscriptionText.trim()
      : '(transcription unavailable — listen to the recording)';
    const recUrl = event.RecordingUrl ? `${event.RecordingUrl}.mp3` : '(no recording)';
    const body =
      `New Priority Print Service voicemail from ${caller}:\n\n` +
      `"${transcript}"\n\n` +
      `Listen: ${recUrl}`;

    const client = context.getTwilioClient();
    client.messages
      .create({ to: notify, from: SMS_FROM, body: body })
      .then(() => callback(null, ''))
      .catch((err) => { console.error('SMS send failed:', err); callback(null, ''); });
    return;
  }

  // --- fallback -------------------------------------------------------------
  twiml.redirect({ method: 'POST' }, `${BASE}?step=menu`);
  return callback(null, twiml);
};
