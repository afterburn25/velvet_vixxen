(() => {
  'use strict';
  const config = window.VELVET_MEDIA_CENTER || {};
  const siteConfig = window.VELVET_CONFIG || {};
  const signedInMember = siteConfig.member || null;
  const memberStateUrl = `${String(siteConfig.baseUrl || '').replace(/\/$/, '')}/api/member-state.php`;
  const saveMemberState = async (type, id, changes = {}) => {
    if (!signedInMember || !type || !id) return null;
    try {
      const response = await fetch(memberStateUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-Token':String(siteConfig.csrfToken||'')},
        body: JSON.stringify(Object.assign({type, id}, changes))
      });
      return response.ok ? await response.json() : null;
    } catch { return null; }
  };
  const tracks = Array.isArray(config.tracks) ? config.tracks.filter(track => track && track.id && track.src) : [];
  const key = 'velvetMediaPlayerV1';
  const format = seconds => {
    seconds = Math.max(0, Math.floor(Number(seconds) || 0));
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    return h ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}` : `${m}:${String(s).padStart(2,'0')}`;
  };
  const readState = () => {
    try { return JSON.parse(localStorage.getItem(key) || '{}') || {}; } catch { return {}; }
  };
  const writeState = state => localStorage.setItem(key, JSON.stringify(state));

  const player = document.querySelector('[data-player]');
  const audio = document.querySelector('[data-player-audio]');
  if (player && audio && tracks.length) {
    player.hidden = false;
    const state = Object.assign({trackId:tracks[0].id,position:0,volume:.8,shuffle:false,repeat:'off',queue:tracks.map(t=>t.id),playing:false}, readState());
    state.queue = Array.isArray(state.queue) ? state.queue.filter(id => tracks.some(t => t.id === id)) : tracks.map(t=>t.id);
    tracks.forEach(t => { if (!state.queue.includes(t.id)) state.queue.push(t.id); });
    if (!tracks.some(t => t.id === state.trackId)) state.trackId = tracks[0].id;
    audio.volume = Math.max(0, Math.min(1, Number(state.volume) || .8));
    const elements = {
      title: document.querySelector('[data-player-title]'), artist: document.querySelector('[data-player-artist]'), cover: document.querySelector('[data-player-cover]'),
      play: document.querySelector('[data-player-play]'), current: document.querySelector('[data-player-current]'), duration: document.querySelector('[data-player-duration]'),
      seek: document.querySelector('[data-player-seek]'), volume: document.querySelector('[data-player-volume]'), shuffle: document.querySelector('[data-player-shuffle]'), repeat: document.querySelector('[data-player-repeat]'),
      drawer: document.querySelector('[data-player-drawer]'), drawerTitle: document.querySelector('[data-drawer-title]'), drawerTrack: document.querySelector('[data-drawer-track]'), drawerArtist: document.querySelector('[data-drawer-artist]'), drawerCover: document.querySelector('[data-drawer-cover]'), queue: document.querySelector('[data-player-queue]')
    };
    let current = null;
    let restoring = true;
    let lastServerSync = 0;
    const trackById = id => tracks.find(t => t.id === id) || tracks[0];
    const persist = () => {
      state.position = Number(audio.currentTime) || 0;
      state.volume = audio.volume;
      state.playing = !audio.paused;
      writeState(state);
    };
    const renderQueue = () => {
      if (!elements.queue) return;
      elements.queue.replaceChildren(...state.queue.map((id,index) => {
        const track = trackById(id);
        const row = document.createElement('div'); row.className = `queue-row${track.id===state.trackId?' active':''}`;
        row.innerHTML = `<button type="button" aria-label="Play ${track.title}">▶</button><img alt=""><div><strong></strong><small></small></div><span>${format(track.duration)}</span>`;
        row.querySelector('img').src = track.cover || '';
        row.querySelector('strong').textContent = track.title;
        row.querySelector('small').textContent = track.artist;
        row.querySelector('button').addEventListener('click', () => load(track.id, true));
        row.draggable = true;
        row.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', String(index)));
        row.addEventListener('dragover', e => e.preventDefault());
        row.addEventListener('drop', e => { e.preventDefault(); const from=Number(e.dataTransfer.getData('text/plain')); if(Number.isInteger(from)&&from!==index){const [moved]=state.queue.splice(from,1);state.queue.splice(index,0,moved);persist();renderQueue();} });
        return row;
      }));
    };
    const updateMeta = () => {
      if (!current) return;
      if (elements.title) elements.title.textContent = current.title;
      if (elements.artist) elements.artist.textContent = current.album ? `${current.artist} · ${current.album}` : current.artist;
      if (elements.cover) { elements.cover.src = current.cover || ''; elements.cover.hidden = !current.cover; }
      if (elements.drawerTrack) elements.drawerTrack.textContent = current.title;
      if (elements.drawerArtist) elements.drawerArtist.textContent = current.artist;
      if (elements.drawerCover) { elements.drawerCover.src=current.cover||''; elements.drawerCover.hidden=!current.cover; }
      if (elements.drawerTitle) elements.drawerTitle.textContent = current.album || 'Velvet Vixxen Player';
      document.querySelectorAll('[data-track-card]').forEach(card => card.classList.toggle('active', card.dataset.trackCard === current.id));
      if ('mediaSession' in navigator) {
        navigator.mediaSession.metadata = new MediaMetadata({title:current.title,artist:current.artist,album:current.album||'',artwork:current.cover?[{src:current.cover,sizes:'512x512'}]:[]});
      }
      renderQueue();
    };
    const load = (id, play=false, position=0) => {
      current = trackById(id); state.trackId = current.id;
      const changed = audio.src !== new URL(current.src, location.href).href;
      if (changed) audio.src = current.src;
      const onReady = () => {
        audio.currentTime = Math.min(Number(position)||0, Number.isFinite(audio.duration)?Math.max(0,audio.duration-.25):Number(position)||0);
        if (play) audio.play().catch(()=>{ state.playing=false; });
        audio.removeEventListener('loadedmetadata', onReady);
      };
      if (changed) audio.addEventListener('loadedmetadata', onReady); else { audio.currentTime=Number(position)||0; if(play) audio.play().catch(()=>{}); }
      updateMeta(); persist();
    };
    const next = direction => {
      if (!current) return;
      const queue = state.queue.length ? state.queue : tracks.map(t=>t.id);
      let index = queue.indexOf(current.id);
      if (state.shuffle && queue.length > 1) { let pick=index; while(pick===index) pick=Math.floor(Math.random()*queue.length); index=pick; }
      else index = (index + direction + queue.length) % queue.length;
      load(queue[index], true, 0);
    };
    document.querySelectorAll('[data-play-track]').forEach(btn => btn.addEventListener('click', () => load(btn.dataset.playTrack, true, 0)));
    document.querySelectorAll('[data-queue-track]').forEach(btn => btn.addEventListener('click', () => { const id=btn.dataset.queueTrack;if(id&&!state.queue.includes(id)){state.queue.push(id);persist();renderQueue();btn.textContent='Queued ✓';} }));
    document.querySelectorAll('[data-play-playlist]').forEach(btn => btn.addEventListener('click', () => { try { const ids=JSON.parse(btn.dataset.playPlaylist||'[]').filter(id=>tracks.some(t=>t.id===id)); if(ids.length){state.queue=ids;load(ids[0],true,0);} } catch {} }));
    elements.play?.addEventListener('click', () => audio.paused ? audio.play().catch(()=>{}) : audio.pause());
    document.querySelector('[data-player-prev]')?.addEventListener('click', () => next(-1));
    document.querySelector('[data-player-next]')?.addEventListener('click', () => next(1));
    elements.seek?.addEventListener('input', () => { if(Number.isFinite(audio.duration)) audio.currentTime=(Number(elements.seek.value)/1000)*audio.duration; });
    elements.volume?.addEventListener('input', () => { audio.volume=Number(elements.volume.value);persist(); });
    elements.shuffle?.addEventListener('click', () => { state.shuffle=!state.shuffle;elements.shuffle.classList.toggle('active',state.shuffle);persist(); });
    elements.repeat?.addEventListener('click', () => { state.repeat=state.repeat==='off'?'all':state.repeat==='all'?'one':'off';elements.repeat.textContent=state.repeat==='one'?'↻¹':state.repeat==='all'?'↻':'↻';elements.repeat.classList.toggle('active',state.repeat!=='off');persist(); });
    document.querySelectorAll('[data-player-expand]').forEach(btn=>btn.addEventListener('click',()=>{elements.drawer.hidden=!elements.drawer.hidden;}));
    document.querySelector('[data-player-close]')?.addEventListener('click',()=>{elements.drawer.hidden=true;});
    audio.addEventListener('play',()=>{document.querySelectorAll('[data-video-player]').forEach(video=>{if(!video.paused)video.pause();});state.playing=true;if(elements.play)elements.play.textContent='Ⅱ';persist();});
    audio.addEventListener('pause',()=>{state.playing=false;if(elements.play)elements.play.textContent='▶';persist();});
    audio.addEventListener('timeupdate',()=>{if(elements.current)elements.current.textContent=format(audio.currentTime);if(elements.duration)elements.duration.textContent=format(audio.duration||current?.duration||0);if(elements.seek&&Number.isFinite(audio.duration)&&audio.duration>0)elements.seek.value=String(Math.round(audio.currentTime/audio.duration*1000));if(current?.previewSeconds>0&&audio.currentTime>=current.previewSeconds){audio.pause();audio.currentTime=0;}if(Math.floor(audio.currentTime)%2===0)persist();if(signedInMember&&current&&Date.now()-lastServerSync>10000){lastServerSync=Date.now();saveMemberState('track',current.id,{progressSeconds:audio.currentTime,viewed:true});}});
    audio.addEventListener('ended',()=>{if(signedInMember&&current)saveMemberState('track',current.id,{progressSeconds:0,completed:true});if(state.repeat==='one')load(current.id,true,0);else next(1);});
    window.addEventListener('pagehide',persist);
    if ('mediaSession' in navigator) {
      navigator.mediaSession.setActionHandler('play',()=>audio.play());navigator.mediaSession.setActionHandler('pause',()=>audio.pause());navigator.mediaSession.setActionHandler('previoustrack',()=>next(-1));navigator.mediaSession.setActionHandler('nexttrack',()=>next(1));navigator.mediaSession.setActionHandler('seekto',details=>{if(details.seekTime!=null)audio.currentTime=details.seekTime;});
    }
    elements.volume.value = String(audio.volume); elements.shuffle?.classList.toggle('active',state.shuffle); elements.repeat?.classList.toggle('active',state.repeat!=='off');
    { const initialTrack=trackById(state.trackId); load(state.trackId, false, Number(state.position)||Number(initialTrack.serverProgress)||0); }
    if (state.playing) audio.play().catch(()=>{ state.playing=false;persist(); });
    restoring = false;
  }

  const lightbox = document.querySelector('[data-lightbox]');
  if (lightbox) {
    const items = [...document.querySelectorAll('[data-lightbox-item]')];
    const image = lightbox.querySelector('[data-lightbox-image]'); const caption=lightbox.querySelector('[data-lightbox-caption]'); const count=lightbox.querySelector('[data-lightbox-count]');
    let index=0,zoom=1;
    const show = nextIndex => { if(!items.length)return; index=(nextIndex+items.length)%items.length; const item=items[index]; image.src=item.dataset.src||'';image.alt=item.dataset.caption||'';caption.textContent=item.dataset.caption||'';count.textContent=`${index+1} / ${items.length}`;zoom=1;image.style.setProperty('--lightbox-zoom',zoom);lightbox.hidden=false;document.body.style.overflow='hidden'; };
    const close=()=>{lightbox.hidden=true;document.body.style.overflow='';};
    items.forEach((item,i)=>item.addEventListener('click',()=>show(i)));
    lightbox.querySelector('[data-lightbox-close]')?.addEventListener('click',close);lightbox.querySelector('[data-lightbox-prev]')?.addEventListener('click',()=>show(index-1));lightbox.querySelector('[data-lightbox-next]')?.addEventListener('click',()=>show(index+1));
    lightbox.querySelector('[data-lightbox-zoom-in]')?.addEventListener('click',()=>{zoom=Math.min(3,zoom+.25);image.style.setProperty('--lightbox-zoom',zoom);});lightbox.querySelector('[data-lightbox-zoom-out]')?.addEventListener('click',()=>{zoom=Math.max(.5,zoom-.25);image.style.setProperty('--lightbox-zoom',zoom);});
    lightbox.addEventListener('click',e=>{if(e.target===lightbox)close();});window.addEventListener('keydown',e=>{if(lightbox.hidden)return;if(e.key==='Escape')close();if(e.key==='ArrowLeft')show(index-1);if(e.key==='ArrowRight')show(index+1);});
    let touchX=0;lightbox.addEventListener('touchstart',e=>{touchX=e.changedTouches[0].clientX},{passive:true});lightbox.addEventListener('touchend',e=>{const delta=e.changedTouches[0].clientX-touchX;if(Math.abs(delta)>55)show(index+(delta<0?1:-1));},{passive:true});
  }

  document.querySelectorAll('[data-video-player]').forEach(video => {
    const id=video.dataset.videoId||'video';const stateKey=`velvetVideoProgress:${id}`;let restored=false;
    video.addEventListener('loadedmetadata',()=>{if(restored)return;restored=true;const local=Number(localStorage.getItem(stateKey)||0);const server=Number(video.dataset.serverProgress||0);const saved=Math.max(local,server);if(saved>2&&saved<video.duration-5)video.currentTime=saved;});
    video.addEventListener('play',()=>{if(audio&&!audio.paused)audio.pause();const playerState=readState();if(playerState.playing){playerState.playing=false;writeState(playerState);}});
    let last=0,lastServer=0;video.addEventListener('timeupdate',()=>{if(Date.now()-last>2000){localStorage.setItem(stateKey,String(video.currentTime));last=Date.now();}if(signedInMember&&Date.now()-lastServer>10000){lastServer=Date.now();saveMemberState('video',id,{progressSeconds:video.currentTime,viewed:true});}});
    video.addEventListener('ended',()=>{localStorage.removeItem(stateKey);saveMemberState('video',id,{progressSeconds:0,completed:true});});
  });

  document.querySelectorAll('[data-favorite-toggle]').forEach(button => {
    button.addEventListener('click', async () => {
      const active = !button.classList.contains('active');
      button.classList.toggle('active', active);
      const original = button.textContent;
      button.textContent = active ? '♥ Saved' : '♡ Save';
      const result = await saveMemberState(button.dataset.contentType, button.dataset.contentId, {isFavorite:active});
      if (!result || !result.ok) { button.classList.toggle('active', !active); button.textContent = original; }
    });
  });
})();
