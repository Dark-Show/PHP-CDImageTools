<?php

function ffmpeg_wav2flac ($wav, $flac) {
    $cmd = "ffmpeg -y -i \"$wav\" -c:a flac \"$flac\" > /dev/null 2>&1";
    passthru ($cmd);
}

function ffmpeg_wav2pcm ($wav, $pcm) {
    $cmd = "ffmpeg -y -i \"$wav\" -f s16le -acodec pcm_s16le \"$pcm\" > /dev/null 2>&1";
    passthru ($cmd);
}

function ffmpeg_flac2wav ($flac, $wav) {
    $cmd = "ffmpeg -y -i \"$flac\" -acodec pcm_s16le \"$wav\" > /dev/null 2>&1";
    passthru ($cmd);
}

function ffmpeg_flac2pcm ($flac, $pcm) {
    $cmd = "ffmpeg -y -i \"$flac\" -f s16le -acodec pcm_s16le \"$pcm\" > /dev/null 2>&1";
    passthru ($cmd);
}

function ffmpeg_pcm2flac ($pcm, $flac, $channels = 2, $sample_rate = 44100) {
    $cmd = "ffmpeg -y -f s16le -ar $sample_rate -ac $channels -i \"$pcm\" -c:a flac \"$flac\" > /dev/null 2>&1";
    passthru ($cmd);
}

function ffmpeg_pcm2wav ($pcm, $wav, $channels = 2, $sample_rate = 44100) {
    $cmd = "ffmpeg -y -f s16le -ar $sample_rate -ac $channels -i \"$pcm\" -c:a copy \"$wav\" > /dev/null 2>&1";
    passthru ($cmd);
}

?>