#!/usr/bin/bash

ffplay -f s16le -ar 44100 -ch_layout stereo -i "$1"