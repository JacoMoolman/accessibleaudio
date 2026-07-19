(() => {
  const firstVoiceNumber = 1;
  const voiceCount = 30;
  const sampleFileOffset = 5;
  const costPerWordCents = 0.75;
  window.ACCESSIBLE_AUDIO_VOICES = Object.freeze(
    Array.from({ length: voiceCount }, (_, index) => {
      const number = firstVoiceNumber + index;
      const padded = String(number).padStart(2, "0");
      const samplePadded = String(number + sampleFileOffset).padStart(2, "0");
      return Object.freeze({
        id: `voice-${padded}`,
        label: `Voice ${number}`,
        typeLabel: "Voice narration",
        costPerWordCents,
        availableForProduction: true,
        sampleUrl: `/assets/voice-samples/catalog/voice-${samplePadded}.mp3`,
      });
    })
  );
})();
