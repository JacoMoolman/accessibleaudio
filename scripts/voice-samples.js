(() => {
  const voices = window.ACCESSIBLE_AUDIO_VOICES || [];
  const lists = {
    local: document.getElementById("local-voice-list"),
    cloud: document.getElementById("cloud-voice-list"),
  };

  voices.forEach((voice) => {
    const list = lists[voice.type];
    if (!list) return;
    const card = document.createElement("article");
    card.className = "voice-card";
    card.innerHTML = `
      <h4>${voice.label}</h4>
      <div class="voice-card-actions">
        <button class="voice-play" type="button">Play</button>
        <button class="voice-stop" type="button" disabled>Stop</button>
      </div>
      <audio preload="none" src="${voice.sampleUrl}"></audio>
    `;
    const audio = card.querySelector("audio");
    const play = card.querySelector(".voice-play");
    const stop = card.querySelector(".voice-stop");
    const reset = () => {
      audio.pause();
      audio.currentTime = 0;
      play.textContent = "Play";
      stop.disabled = true;
    };
    play.addEventListener("click", async () => {
      document.querySelectorAll(".voice-card audio").forEach((other) => {
        if (other !== audio) {
          other.pause();
          other.currentTime = 0;
          const otherCard = other.closest(".voice-card");
          otherCard.querySelector(".voice-play").textContent = "Play";
          otherCard.querySelector(".voice-stop").disabled = true;
        }
      });
      if (audio.paused) {
        try {
          await audio.play();
          play.textContent = "Playing";
          stop.disabled = false;
        } catch {
          reset();
        }
      } else {
        reset();
      }
    });
    stop.addEventListener("click", reset);
    audio.addEventListener("ended", reset);
    list.append(card);
  });
})();
