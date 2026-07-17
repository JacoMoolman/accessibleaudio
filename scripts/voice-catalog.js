(() => {
  const voiceCount = 35;
  const localVoiceCount = 5;
  const localCostPerWordCents = 0.5;
  const cloudCostPerWordCents = localCostPerWordCents * 1.5;
  window.ACCESSIBLE_AUDIO_VOICES = Object.freeze(
    Array.from({ length: voiceCount }, (_, index) => {
      const number = index + 1;
      const padded = String(number).padStart(2, "0");
      const extension = number <= 5 ? "wav" : "mp3";
      const type = number <= localVoiceCount ? "local" : "cloud";
      return Object.freeze({
        id: `voice-${padded}`,
        label: `Voice ${number}`,
        type,
        typeLabel: type === "local" ? "Local voices" : "Cloud voices",
        costPerWordCents:
          type === "local" ? localCostPerWordCents : cloudCostPerWordCents,
        availableForProduction: number >= 6 && number <= 10,
        sampleUrl: `/assets/voice-samples/catalog/voice-${padded}.${extension}`,
      });
    })
  );
})();
