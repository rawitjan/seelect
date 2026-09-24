import { readFileSync, writeFileSync, mkdirSync, existsSync, renameSync } from 'node:fs';
import { resolve } from 'node:path';
import { execFileSync } from 'node:child_process';

const root = import.meta.dirname;
const ffmpeg = resolve(root, '../../../storage/app/video-tools/node_modules/ffmpeg-static/ffmpeg.exe');
const scenes = JSON.parse(readFileSync(resolve(root, 'scenes.json')));
const sources = JSON.parse(readFileSync(resolve(root, 'audio-sources.json')));
const audioDir = resolve(root, 'assets/audio');
mkdirSync(audioDir, { recursive: true });
const run = args => execFileSync(ffmpeg, ['-hide_banner', '-loglevel', 'error', '-y', ...args], { stdio: 'inherit' });
const words = [];
const manifest = [];
for (const scene of scenes) {
  const source = sources.find(item => item.n === scene.n);
  if (!source?.audio_url) throw new Error(`No narration for scene ${scene.n}`);
  const raw = resolve(audioDir, `raw-${scene.n}.wav`);
  if (!existsSync(raw)) {
    const response = await fetch(source.audio_url);
    if (!response.ok) throw new Error(`Voice ${scene.n}: ${response.status}`);
    writeFileSync(raw, Buffer.from(await response.arrayBuffer()));
  }
  const trim = 0.16;
  const target = scene.duration - 0.6;
  const rate = Math.max(1, (source.duration - trim) / target);
  const out = resolve(audioDir, `voice-${scene.n}.wav`);
  run(['-i', raw, '-af', `atrim=start=${trim},asetpts=PTS-STARTPTS,atempo=${rate.toFixed(6)},loudnorm=I=-16:TP=-1.5:LRA=9,aresample=48000,asetpts=PTS-STARTPTS,adelay=200|200,apad=whole_dur=${scene.duration}`, '-t', String(scene.duration), '-ar', '48000', '-ac', '1', out]);
  const fixed = resolve(audioDir, `voice-${scene.n}-fixed.wav`);
  run(['-i', out, '-af', 'apad', '-t', String(scene.duration), '-c:a', 'pcm_s16le', fixed]);
  renameSync(fixed, out);
  for (const word of source.word_timestamps.filter(word => !word.word.startsWith('<'))) {
    words.push({ text: word.word, start: scene.start + 0.2 + Math.max(0, word.start - trim) / rate, end: scene.start + 0.2 + Math.max(0, word.end - trim) / rate, scene: scene.n });
  }
  manifest.push({ id: `voice-${scene.n}`, type: 'voice', file: `assets/audio/voice-${scene.n}.wav`, provider: 'HeyGen Starfish', voice: 'Godric - Firm & Measured', duration: scene.duration, sourceDuration: source.duration, tempo: rate });
  console.log(`voice ${scene.n}: ${source.duration.toFixed(2)}s → ${scene.duration}s (tempo ${rate.toFixed(2)})`);
}
const list = resolve(audioDir, 'concat.txt');
writeFileSync(list, scenes.map(scene => `file 'voice-${scene.n}.wav'`).join('\n'));
run(['-f', 'concat', '-safe', '0', '-i', list, '-c:a', 'pcm_s16le', resolve(audioDir, 'narration.wav')]);
const captions = [];
let group = [];
for (let i = 0; i < words.length; i++) {
  const word = words[i];
  group.push(word);
  if (group.length >= 6 || /[.!?]$/.test(word.text) || words[i + 1]?.scene !== word.scene) {
    captions.push({ start: group[0].start, end: word.end + 0.12, text: group.map(word => word.text).join(' ').replace(/сии лект/gi, 'seelect').replace(/эн экс ти/gi, 'nxt'), scene: word.scene });
    group = [];
  }
}
writeFileSync(resolve(root, 'captions.json'), JSON.stringify(captions, null, 2));
writeFileSync(resolve(root, 'assets/media-manifest.json'), JSON.stringify(manifest, null, 2));
const stamp = (seconds, separator = '.') => {
  const milliseconds = Math.round(seconds * 1000);
  return `${String(Math.floor(milliseconds / 3600000)).padStart(2, '0')}:${String(Math.floor(milliseconds / 60000) % 60).padStart(2, '0')}:${String(Math.floor(milliseconds / 1000) % 60).padStart(2, '0')}${separator}${String(milliseconds % 1000).padStart(3, '0')}`;
};
writeFileSync(resolve(root, 'captions.ru.vtt'), 'WEBVTT\n\n' + captions.map(caption => `${stamp(caption.start)} --> ${stamp(caption.end)}\n${caption.text}\n`).join('\n'));
writeFileSync(resolve(root, 'captions.ru.srt'), captions.map((caption, i) => `${i + 1}\n${stamp(caption.start, ',')} --> ${stamp(caption.end, ',')}\n${caption.text}\n`).join('\n'));

const music = resolve(audioDir, 'music-source.wav');
if (existsSync(music)) {
  run(['-i', music, '-i', music, '-i', music, '-filter_complex', '[0:a][1:a]acrossfade=d=1.5:c1=tri:c2=tri[a];[a][2:a]acrossfade=d=1.5:c1=tri:c2=tri,atrim=duration=85,afade=t=in:d=0.4,afade=t=out:st=81.5:d=3.5,loudnorm=I=-21:TP=-2:LRA=7[out]', '-map', '[out]', '-ar', '48000', '-ac', '2', resolve(audioDir, 'music.wav')]);
}
