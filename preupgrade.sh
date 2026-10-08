#!/bin/sh

# Bash script which is executed in case of an update (if this plugin is already
# installed on the system). This script is executed as very first step (*BEFORE*
# preinstall.sh) and can be used e.g. to save existing configfiles to /tmp
# during installation. Use with caution and remember, that all systems may be
# different!
#
# Exit code must be 0 if executed successfull.
# Exit code 1 gives a warning but continues installation.
# Exit code 2 cancels installation.
#
# Will be executed as user "loxberry".
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
PTEMPPATH=$6  # Sixth argument is full temp path during install (see also $1)

# Base folder of LoxBerry

# Combine them with /etc/environment
PCGI=$LBPCGI/$PDIR
PHTML=$LBPHTML/$PDIR
PTEMPL=$LBPTEMPL/$PDIR
PDATA=$LBPDATA/$PDIR
PLOG=$LBPLOG/$PDIR # Note! This is stored on a Ramdisk now!
PCONFIG=$LBPCONFIG/$PDIR
PSBIN=$LBPSBIN/$PDIR
PBIN=$LBPBIN/$PDIR

#source version file
. ${PTEMPPATH}/version.sh

echo "<INFO> Checking if zigbee2mqtt repository is reachable before upgrade"
git ls-remote --exit-code https://github.com/Koenkk/zigbee2mqtt.git refs/tags/$ZIGBEE2MQTT_VERSION
retVal=$?
if [ $retVal -ne 0 ]; then
    echo "<ERROR> Could not reach zigbee2mqtt repository. Please check if your loxberry has an internet connection."
    exit 2
fi

# 4.1.1 (B5): the installer removes config/ and data/ of the plugin right
# after this script. The copy below holds the network key - it is checked,
# and if it is incomplete the update is cancelled (exit 2) instead of
# going on without the Zigbee network. zigbee2mqtt is stopped first, so the
# files do not change while they are copied (preroot.sh would stop it a
# moment later anyway); on a cancelled update it is started again.
SICHERUNG=/tmp/${PTEMPDIR}_upgrade
abbrechen() {
    echo "<FAIL> $1"
    echo "<FAIL> The update is cancelled, nothing was changed. The Zigbee network stays as it is."
    if [ "$GESTOPPT" = "1" ]; then
        sudo -n systemctl start zigbee2mqttng >/dev/null 2>&1 && echo "<INFO> zigbee2mqtt started again"
    fi
    exit 2
}

GESTOPPT=0
if systemctl is-active --quiet zigbee2mqttng; then
    echo "<INFO> Stopping zigbee2mqtt for the backup"
    if timeout 60 sudo -n systemctl stop zigbee2mqttng >/dev/null 2>&1; then
        GESTOPPT=1
    else
        echo "<WARNING> zigbee2mqtt could not be stopped - its files are copied while it runs"
    fi
fi

echo "<INFO> Creating temporary folders for upgrading"
# The copy holds the network key and the broker password: only for loxberry
umask 077
mkdir "$SICHERUNG" "$SICHERUNG/config" "$SICHERUNG/data" || abbrechen "Could not create $SICHERUNG"

echo "<INFO> Backing up existing files"
cp -p -r "$PCONFIG/" "$SICHERUNG/config" || abbrechen "Copying $PCONFIG failed (disk full?)"
cp -p -r "$PDATA/" "$SICHERUNG/data" || abbrechen "Copying $PDATA failed (disk full?)"

# Check: every file of config/ and the files that hold the network must be
# in the copy, byte for byte
for f in "$PCONFIG"/*; do
    [ -f "$f" ] || continue
    cmp -s "$f" "$SICHERUNG/config/$PDIR/$(basename "$f")" || abbrechen "Backup of $(basename "$f") is incomplete"
done
for f in configuration.yaml coordinator_backup.json database.db devices.yaml groups.yaml; do
    [ -f "$PDATA/$f" ] || continue
    cmp -s "$PDATA/$f" "$SICHERUNG/data/$PDIR/$f" || abbrechen "Backup of $f is incomplete"
done
echo "<OK> Backup checked: $(find "$SICHERUNG" -type f | wc -l) files in $SICHERUNG"

# Second copies next to the config folder - the second line of defence if
# the copy above is lost (B3)
php "$PTEMPPATH/bin/zweitschrift.php" ziehen "$PCONFIG" "$PDATA"

# Exit with Status 0
exit 0
