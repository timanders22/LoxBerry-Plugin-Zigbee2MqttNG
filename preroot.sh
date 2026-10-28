#!/bin/sh

# Bash script which is executed by bash *BEFORE* installation is started
# (*BEFORE* preinstall but *AFTER* preupdate). Use with caution and remember,
# that all systems may be different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
# Will be executed as user "root".
# !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
#
# You can use all vars from /etc/environment in this script.
#
# We add 5 additional arguments when executing this script:
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# For logging, print to STDOUT. You can use the following tags for showing
# different colorized information during plugin installation:
#
# <OK> This was ok!"
# <INFO> This is just for your information."
# <WARNING> This is a warning!"
# <ERROR> This is an error!"
# <FAIL> This is a fail!"

# To use important variables from command line use the following code:
COMMAND=$0  # Zero argument is shell command
PTEMPDIR=$1 # First argument is temp folder during install
PSHNAME=$2  # Second argument is Plugin-Name for scipts etc.
PDIR=$3     # Third argument is Plugin installation folder
PVERSION=$4 # Forth argument is Plugin version
#LBHOMEDIR=$5 # Comes from /etc/environment now. Fifth argument is
# Base folder of LoxBerry

# 4.2.5 (Gruppe 38, V6): the LoxBerry home comes from /etc/environment; the
# installer passes it as fifth argument too (plugininstall.pl). Without both,
# and with a folder that is not the own one, nothing is done - a path built
# from an empty variable would be "/zigbee2mqttng" (chown -R as root).
LBHOMEDIR="${LBHOMEDIR:-$5}"
case "$PDIR" in zigbee2mqttng) ;; *) echo "<FAIL> unexpected plugin folder '$PDIR'"; exit 2;; esac
case "$LBHOMEDIR" in /?*) ;; *) echo "<FAIL> LoxBerry home folder unknown"; exit 2;; esac
LBPCGI="${LBPCGI:-$LBHOMEDIR/webfrontend/htmlauth/plugins}"
LBPHTML="${LBPHTML:-$LBHOMEDIR/webfrontend/html/plugins}"
LBPTEMPL="${LBPTEMPL:-$LBHOMEDIR/templates/plugins}"
LBPDATA="${LBPDATA:-$LBHOMEDIR/data/plugins}"
LBPLOG="${LBPLOG:-$LBHOMEDIR/log/plugins}"
LBPCONFIG="${LBPCONFIG:-$LBHOMEDIR/config/plugins}"
LBPSBIN="${LBPSBIN:-$LBHOMEDIR/sbin/plugins}"
LBPBIN="${LBPBIN:-$LBHOMEDIR/bin/plugins}"

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

# 4.2.5 (Gruppe 38, V5): the installer runs THIS script before preupgrade.sh
# (plugininstall.pl :846 preroot, :872 preupgrade) - here the service is
# stopped first, so the marker "was running before" is written here, next to
# the config folder (it survives purge_installation). postroot.sh starts
# zigbee2mqtt after an update only with it; a stopped service stays stopped.
LIEF_VORHER="$LBPCONFIG/$PDIR.lief_vorher"
# a marker left by an earlier, broken update says nothing about now
rm -f "$LIEF_VORHER"

# 4.2.5 (V6): stop first, then remove the link - zigbee2mqtt must not run
# without its data folder
echo "<INFO> Stopping service if already running"
if systemctl is-active --quiet zigbee2mqttng; then
	if [ -d "$LBPCONFIG" ] && (umask 077; date +%s > "$LIEF_VORHER"); then
		chown loxberry:loxberry "$LIEF_VORHER" 2>/dev/null
		echo "<INFO> zigbee2mqtt runs - it is started again after the update"
	fi
	timeout 60 systemctl stop zigbee2mqttng || echo "<WARNING> zigbee2mqttng did not stop within 60 s"
fi

if [ -L /opt/zigbee2mqttng/data ] || [ -e /opt/zigbee2mqttng/data ]; then
	echo "<INFO> Removing zigbee2mqtt symlink from plugin before update"
	unlink /opt/zigbee2mqttng/data
	echo "<INFO> The data symlink will be recreated during installation"
fi
