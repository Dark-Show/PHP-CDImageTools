<?php

const CDROM_SECTOR = 2352;
const CDROM_SECTORS_SECOND = 75; // Sectors per second
const CDROM_CUE_TAB = '  ';
const CDROM_AUDIO_RAW = 0;
const CDROM_AUDIO_WAVE = 1;
const CDROM_AUDIO_FLAC = 2;

// Load cue file into cue array
function cdrom_open_cue ($cue_file) {
	$disk = array('track' => array());
	$cue = file ($cue_file);
	$disk['path'] = '';
	if (strpos ($cue_file, '/') !== false) {
		$disk['path'] = explode ('/', $cue_file);
		$disk['cue'] = $disk['path'][count ($disk['path']) - 1];
		unset ($disk['path'][count ($disk['path']) - 1]);
		$disk['path'] = implode ('/', $disk['path']) . '/';
	}
	$file_count = 0;
	foreach ($cue as $line){
		if (strtolower (substr ($line, 0, 4)) == "file") {
			$p1 = strpos ($line, '"') + 1;
			$p2 = strpos ($line, '"', $p1) - $p1;
			$file = substr ($line, $p1, $p2);
			$type = explode (' ', trim ($line));
			$type = $type[count ($type) - 1];
			$sector_count = false;
			if ($disk['path'] !== false and file_exists ($disk['path'] . $file))
				$sector_count = filesize ($disk['path'] . $file) / CDROM_SECTOR;
			else if (file_exists ($file))
				$sector_count = filesize ($file) / CDROM_SECTOR;
			$file_count++;
		} else if (strtolower (substr (trim ($line), 0, 5)) == "track") {
			if (isset ($ntrack)) {
				$track['index'] = $index;
				$disk['track'][$ntrack] = $track;
				unset ($ntrack);
				$track = array();
				$index = array();
			}
			$track['file'] = $file;
			$track['file_sectors'] = $sector_count;
			$track['file_type'] = $type;
			$t = explode (' ', trim ($line));
			$ntrack = (int)$t[1];
			$track['track_type'] = $t[2];
		} else if (strtolower (substr (trim ($line), 0, 6)) == "pregap") {
			$t = explode (' ', trim ($line));
			$track['pregap'] = $t[1];
		} else if (strtolower (substr (trim ($line), 0, 5)) == "index") {
			$t = explode (' ', trim ($line));
			$nindex = (int)$t[1];
			$index[$nindex] = $t[2];
		}
	}
	if (isset ($index))
		$track['index'] = $index;
	if (isset ($track))
		$disk['track'][$ntrack] = $track;
	$disk['file_count'] = $file_count;
	return ($disk);
}

// Save cue array to file
function cdrom_save_cue ($disk, $cue_file) {
	$data = "";
	$last_file = false;
	foreach ($disk['track'] as $track => $info) {
		if (!is_numeric ($track))
			continue;
		if ($last_file === false or $last_file != $info['file']) {
			$last_file = $info['file'];
			$data .= 'FILE "' . $info['file'] . '" ' . $info['file_type'] . "\n";
		}
		$data .= CDROM_CUE_TAB . "TRACK " . ($track < 10 ? '0' : '') .  "$track " . $info['track_type'] . "\n";
		if (isset ($info['pregap']))
			$data .= CDROM_CUE_TAB . CDROM_CUE_TAB . "PREGAP " . $info['pregap'] . "\n";
		foreach ($info['index'] as $index => $time)
			$data .= CDROM_CUE_TAB . CDROM_CUE_TAB . "INDEX " . ($index < 10 ? '0' : '') . "$index $time\n";
	}
	$fh = fopen ($cue_file, "wb");
	fwrite ($fh, $data);
	fclose ($fh);
}

// Concatenate multifile cue track into single binary file
function cdrom_concatenate_cue (&$disk, $basename, $output_dir) {
    $pos = '00:00:00';
	$l_file = false;
	$o_fh = fopen ("$output_dir$basename.bin", "wb");
	foreach ($disk['track'] as $track => $info) {
		if (!is_numeric ($track))
			continue;
		if ($l_file == $info['file'])
			continue;
		$l_file = $info['file'];
		
		$delete = false;
		switch ($info['file_type']) {
			case 'BINARY':
				$i_fh = fopen ($disk['path'] . $info['file'], "rb");
				break;
			case 'WAVE':
				if ($info['track_type'] != 'AUDIO')
					break;
				ffmpeg_wav2pcm ($disk['path'] . $info['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.bin");
				$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.bin";
				$disk['track'][$track]['file_type'] = 'BINARY';
				$disk['track'][$track]['file_sectors'] = filesize ($output_dir . $disk['track'][$track]['file']) / CDROM_SECTOR;
				$delete = true;
				$i_fh = fopen ($output_dir . $disk['track'][$track]['file'], "rb");
				break;
			case 'FLAC':
				if ($info['track_type'] != 'AUDIO')
					break;
				ffmpeg_flac2pcm ($disk['path'] . $info['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.bin");
				$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.bin";
				$disk['track'][$track]['file_type'] = 'BINARY';
				$disk['track'][$track]['file_sectors'] = filesize ($output_dir . $disk['track'][$track]['file']) / CDROM_SECTOR;
				$delete = true;
				$i_fh = fopen ($output_dir . $disk['track'][$track]['file'], "rb");
				break;
			default:
				return (false);
		}
		
        while (!feof ($i_fh)) {
            $data = fread ($i_fh, 4096);
            fwrite ($o_fh, $data);
        }
        fclose ($i_fh);
		
		if ($delete)
			unlink ($output_dir . $disk['track'][$track]['file']);
		
		$disk['track'][$track]['file'] = "$basename.bin";
		$disk['track'][$track]['file_type'] = 'BINARY';
		
		if (isset ($disk['track'][$track - 1])) {
			if ($info['file_sectors'] === false)
				return (false);
			if (isset ($info['index'][0])) {
				$pos = cdrom_msf_add (cdrom_lba2msf ($disk['track'][$track - 1]['file_sectors']), $pos);
				$gap = cdrom_msf_sub ($info['index'][0], $info['index'][1]);
				$disk['track'][$track]['index'][1] = $pos;
				$disk['track'][$track]['index'][0] = cdrom_msf_sub ($gap, $pos);
			} else {
				$pos = cdrom_msf_add (cdrom_lba2msf ($disk['track'][$track - 1]['file_sectors']), $pos);
				$disk['track'][$track]['index'][1] = $pos;
			}
		}
	}
    fclose ($o_fh);
	return (true);
}

// Split cue into track per binary file
function cdrom_split_cue (&$disk, $basename, $output_dir, $comp = CDROM_AUDIO_RAW) {
    $i_fh = false;
	$i_file = false;
	foreach ($disk['track'] as $track => $info) {
		if (!is_numeric ($track))
			continue;
		
		$delete = false;
		if (isset ($info['file'])) {
			switch ($info['file_type']) {
				case 'BINARY':
					if ($i_fh === false or ($i_file != $info['file'])) {
						if (is_resource ($i_fh))
							fclose ($i_fh);
						$i_fh = fopen ($disk['path'] . $info['file'], "rb");
						$i_file = $info['file'];
					}
					$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.bin";
					$o_fh = fopen ($output_dir . $disk['track'][$track]['file'], "wb");
					if (isset ($disk['track'][$track + 1]) and $info['file'] == $disk['track'][$track + 1]['file']) {
						$end = cdrom_msf2lba ($disk['track'][$track + 1]['index'][1]) * CDROM_SECTOR;
						while (!feof ($i_fh) and ftell ($i_fh) < $end) {
							$data = fread ($i_fh, CDROM_SECTOR);
							fwrite ($o_fh, $data);
						}
					} else {
						while (!feof ($i_fh)) {
							$data = fread ($i_fh, 4096);
							fwrite ($o_fh, $data);
						}
					}
					fclose ($o_fh);
					break;
				case 'WAVE':
					ffmpeg_wav2pcm ($disk['path'] . $disk['track'][$track]['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.bin");
					$delete = true;
					$disk['track'][$track]['file_type'] = "BINARY";
					$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.bin";
					break;
				case 'FLAC':
					ffmpeg_flac2pcm ($disk['path'] . $disk['track'][$track]['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.bin");
					$delete = true;
					$disk['track'][$track]['file_type'] = "BINARY";
					$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.bin";
					break;
				default:
					return (false);
			}
		}
		
		$disk['track'][$track]['file_sectors'] = filesize ($output_dir . $disk['track'][$track]['file']) / CDROM_SECTOR;
		if (isset ($info['index'][0])) {
            $gap = cdrom_msf_sub ($info['index'][0], $info['index'][1]);
            $disk['track'][$track]['index'][0] = '00:00:00';
            $disk['track'][$track]['index'][1] = $gap;
        } else
			$disk['track'][$track]['index'][1] = '00:00:00';
		
		if ($info['track_type'] != 'AUDIO')
			continue;
		switch ($comp) {
			case CDROM_AUDIO_RAW:
				break;
			case CDROM_AUDIO_WAVE:
				ffmpeg_pcm2wav ($output_dir . $disk['track'][$track]['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.wav");
				$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.wav";
				$disk['track'][$track]['file_type'] = "WAVE";
				$delete = true;
				break;
			case CDROM_AUDIO_FLAC:
				ffmpeg_pcm2flac ($output_dir . $disk['track'][$track]['file'], "$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.flac");
				$disk['track'][$track]['file'] = "$basename." . ($track < 10 ? '0' : '') . "$track.flac";
				$disk['track'][$track]['file_type'] = "FLAC";
				$delete = true;
				break;
			default:
				return (false);
		}
		if ($delete)
			unlink ("$output_dir$basename." . ($track < 10 ? '0' : '') . "$track.bin");
	}
	fclose ($i_fh);
	return (true);
}

// Convert header tracking to sector count
function cdrom_header2msf ($h) {
	$mins = str_pad (ord (substr ($h, 0, 1)), 2, "0", STR_PAD_LEFT);
	$secs = str_pad (ord (substr ($h, 1, 1)), 2, "0", STR_PAD_LEFT);
	$frames = str_pad (ord (substr ($h, 2, 1)), 2, "0", STR_PAD_LEFT);
   	return ("$mins:$secs:$frames");
}

// CD Atime to Sector
function cdrom_msf2lba ($t) {
    $time = explode (':', $t);
	$mins = $time[0];
	$secs = $time[1];
	$frames = $time[2];
	return (CDROM_SECTORS_SECOND * ($mins * 60 + $secs) + $frames);
}

// Sector to CD ATime
function cdrom_lba2msf ($s) {
	$seconds = intval ($s / CDROM_SECTORS_SECOND);
	$frames = $s - ($seconds * CDROM_SECTORS_SECOND);
	$minutes = intval ($seconds / 60);
	$seconds -= $minutes * 60;
	return (str_pad ($minutes, 2, "0", STR_PAD_LEFT) . ':' . str_pad ($seconds, 2, "0", STR_PAD_LEFT) . ':' . str_pad ($frames, 2, "0", STR_PAD_LEFT));
}

// Subtract CD ATime
function cdrom_msf_sub ($t1, $t2) {
	$t1 = explode (':', $t1);
	$t2 = explode (':', $t2);
	
	$t[0] = (int)$t2[0] - (int)$t1[0];
	$t[1] = (int)$t2[1] - (int)$t1[1];
	$t[2] = (int)$t2[2] - (int)$t1[2];
	
	if ($t[2] < 0) {
		$t[2] += CDROM_SECTORS_SECOND;
		$t[1] -= 1;
	}
	if ($t[1] < 0) {
		$t[1] += 60;
		$t[0] -= 1;
	}
	
	$t[0] = str_pad ($t[0], 2, "0", STR_PAD_LEFT);
	$t[1] = str_pad ($t[1], 2, "0", STR_PAD_LEFT);
	$t[2] = str_pad ($t[2], 2, "0", STR_PAD_LEFT);
	$t = implode (':', $t);
	return ($t);
}

// Add CD ATime
function cdrom_msf_add ($t1, $t2) {
	$t1 = explode (':', $t1);
	$t2 = explode (':', $t2);
	
	$t[0] = (int)$t1[0] + (int)$t2[0];
	$t[1] = (int)$t1[1] + (int)$t2[1];
	$t[2] = (int)$t1[2] + (int)$t2[2];
	
	if ($t[2] >= CDROM_SECTORS_SECOND) {
		$t[2] -= CDROM_SECTORS_SECOND;
		$t[1] += 1;
	}
	if ($t[1] >= 60) {
		$t[1] -= 60;
		$t[0] += 1;
	}
	
	$t[0] = str_pad ($t[0], 2, "0", STR_PAD_LEFT);
	$t[1] = str_pad ($t[1], 2, "0", STR_PAD_LEFT);
	$t[2] = str_pad ($t[2], 2, "0", STR_PAD_LEFT);
	$t = implode (':', $t);
	return ($t);
}

?>