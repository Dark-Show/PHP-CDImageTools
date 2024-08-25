#!/usr/bin/env php
<?php

// Title: CUE/BIN Conversion Tool
// Description: Convert BIN format while retaining CUE compatibility
// Author: Greg Michalik
const VERSION = '0.1';

include ('include/ffmpeg.php');
include ('include/cuebin.php');

const CUEBIN_MODE_CONCATENATE = 0;
const CUEBIN_MODE_SPLIT = 1;

if (count ($argv) == 1)
	display_help ($argv);

$out_dir = "./";
$comp = CDROM_AUDIO_RAW;
$mode = CUEBIN_MODE_CONCATENATE;
for ($i = 1; $i < count ($argv); $i++) {
	switch ($argv[$i]) {
		case '-cue':
			if (!isset ($argv[$i + 1]))
				die ("Error: Invalid arguments\n");
			$cue_file = $argv[$i + 1];
			if (!is_file ($cue_file))
				die ("Error: Can not access '$cue_file'\n");
			$i++;
			break;
		case '-basename':
			if (!isset ($argv[$i + 1]))
				die ("Error: Missing basename\n");
			$basename = $argv[$i + 1];
			$i++;
			break;
		case '-output':
			if (!isset ($argv[$i + 1]))
				die ("Error: Missing output directory\n");
			$out_dir = $argv[$i + 1];
			if (!is_dir ($out_dir) and !mkdir ($out_dir, 0777, true))
				die ("Error: Could not create directory '$out_dir'\n");
			if (substr ($out_dir, -1, 1) != '/')
				$out_dir .= '/';
			$i++;
			break;
		case '-concatenate':
			$mode = CUEBIN_MODE_CONCATENATE;
			break;
		case '-split':
			$mode = CUEBIN_MODE_SPLIT;
			break;
		case '-audio':
			if (!isset ($argv[$i + 1]))
				die ("Error: Missing audio format\n");
			$mode = CUEBIN_MODE_SPLIT;
			$comp = $argv[$i + 1];
			switch ($comp) {
				case 'raw':
					$comp = CDROM_AUDIO_RAW;
					break;
				case 'wave':
					$comp = CDROM_AUDIO_WAVE;
					break;
				case 'flac':
					$comp = CDROM_AUDIO_FLAC;
					break;
				default:
					die("Error: Audio format not supported\n");
			}
			$i++;
			break;
		default:
			display_help ($argv);
	}
}
if (!isset ($cue_file))
	echo ("Error: No CUE file specified\n");

if (!isset ($basename)) {
	$basename = explode ('/', $cue_file);
	$basename = $basename[count ($basename) - 1];
	$basename = substr ($basename, 0, -4);
}

$disk = cdrom_open_cue ($cue_file);

switch ($mode) {
	case CUEBIN_MODE_CONCATENATE:
		cdrom_concatenate_cue ($disk, $basename, $out_dir);
		break;
	case CUEBIN_MODE_SPLIT:
		cdrom_split_cue ($disk, $basename, $out_dir, $comp);
		break;
	default:
		die();
}
cdrom_save_cue ($disk, "$out_dir$basename.cue");

function display_help ($argv) {
	echo ("Cuebin Tools v" . VERSION . "\n" .
		  "  Arguments:\n" .
		  "    -cue \"FILE.CUE\"          Input CUE file\n" .
		  "    -basename \"DISC_NAME\"    Output filename\n" .
		  "    -output \"PATH/\"          Output directory\n" .
		  "    -concatenate             Concatenate CUE/BIN files\n" .
		  "    -split                   Split CUE/BIN into file per track\n" .
		  "    -audio flac|wave|raw     Compress audio tracks to format\n\n" .
		  "  Example Usages:\n" .
		  "    " . $argv[0] . " -cue \"input.cue\" -output \"output/\" -split\n" .
		  "    " . $argv[0] . " -cue \"input.cue\" -output \"output/\" -concatenate\n" .
		  "    " . $argv[0] . " -cue \"input.cue\" -output \"output/\" -audio flac\n");
	die();
}

?>