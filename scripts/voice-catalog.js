(() => {
  const firstVoiceNumber = 6;
  const voiceCount = 30;
  const costPerWordCents = 0.75;
  window.ACCESSIBLE_AUDIO_VOICES = Object.freeze(
    Array.from({ length: voiceCount }, (_, index) => {
      const number = firstVoiceNumber + index;
      const padded = String(number).padStart(2, "0");
      return Object.freeze({
        id: `voice-${padded}`,
        label: `Voice ${number}`,
        typeLabel: "Voice narration",
        costPerWordCents,
        availableForProduction: true,
        sampleUrl: `/assets/voice-samples/catalog/voice-${padded}.mp3`,
      });
    })
  );
})();
