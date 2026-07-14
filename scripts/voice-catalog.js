(() => {
  const voiceCount = 35;
  window.ACCESSIBLE_AUDIO_VOICES = Object.freeze(
    Array.from({ length: voiceCount }, (_, index) => {
      const number = index + 1;
      const padded = String(number).padStart(2, "0");
      const extension = number <= 5 ? "wav" : "mp3";
      return Object.freeze({
        id: `voice-${padded}`,
        label: `Voice ${number}`,
        sampleUrl: `/assets/voice-samples/catalog/voice-${padded}.${extension}`,
      });
    })
  );
})();
