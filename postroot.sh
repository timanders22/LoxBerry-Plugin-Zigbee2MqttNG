#!/bin/bash

# Bashscript which is executed by bash *AFTER* complete installation is done
# (*AFTER* postinstall but *BEFORE* postupdate). Use with caution and remember,
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

echo "<INFO> Command is: $COMMAND"
echo "<INFO> Temporary folder is: $PTEMPDIR"
echo "<INFO> (Short) Name is: $PSHNAME"
echo "<INFO> Loxberry Home is: $LBHOMEDIR"
echo "<INFO> Plugin installation folder is: $PDIR"

#source version file
. ${PTEMPPATH}/version.sh

# Zigbee2MqttNG installs into its own folder and runs its own service, so it never
# collides with the original Zigbee2Mqtt plugin (/opt/zigbee2mqtt, zigbee2mqtt.service)
# or with Zigbee2Lox, the former name of this plugin (/opt/zigbee2lox, zigbee2lox.service).
INSTALLDIR=/opt/zigbee2mqttng
SERVICE=zigbee2mqttng
# Folders (= service names) of the predecessor plugins whose network is taken
# over once on a fresh install. The first one found wins: Zigbee2Lox already
# took over the original, so it holds the newest data.
PREDECESSORS="zigbee2lox zigbee2mqtt"


BUILDDIR=$INSTALLDIR.new

ISUPGRADE=0
if [ -d "/tmp/${PTEMPDIR}_upgrade" ]; then
    echo "<INFO> Upgrade detected"
    ISUPGRADE=1

    #Replace service config in backup because it is copied back in the next step
    if [ -d "$LBHOMEDIR/config/plugins/$PDIR" ]; then
        cp -f -r $LBHOMEDIR/config/plugins/$PDIR/*.service /tmp/${PTEMPDIR}_upgrade/config/$PDIR/
    fi

    echo "<INFO> Copy back existing config files"
    if [ -d "/tmp/${PTEMPDIR}_upgrade/config/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/config/$PDIR/* $LBHOMEDIR/config/plugins/$PDIR/
    fi
    if [ -d "/tmp/${PTEMPDIR}_upgrade/data/$PDIR" ]; then
        cp -f -r /tmp/${PTEMPDIR}_upgrade/data/$PDIR/* $LBHOMEDIR/data/plugins/$PDIR/
    fi
fi
rm -f -r /tmp/${PTEMPDIR}_upgrade

# ---------------------------------------------------------------------------
# Build zigbee2mqtt in $BUILDDIR. The running installation in $INSTALLDIR is
# only replaced once the build succeeded - a failed download or build keeps
# the previous zigbee2mqtt, so the Zigbee network keeps working.
# ---------------------------------------------------------------------------
BUILD_OK=0
BUILD_ERROR=""

build_zigbee2mqtt() {
    ARCH=$(uname -m)
    case $ARCH in
      x86_64)  NODE_ARCH="x64" ;;
      aarch64) NODE_ARCH="arm64" ;;
      armv7l)  NODE_ARCH="armv7l" ;;
      *)
        BUILD_ERROR="Unsupported architecture $ARCH - zigbee2mqtt needs x86_64, aarch64 or armv7l"
        return 1
        ;;
    esac

    rm -f -r "$BUILDDIR"
    echo "<INFO> Downloading zigbee2mqtt $ZIGBEE2MQTT_VERSION"
    git clone --quiet --branch "$ZIGBEE2MQTT_VERSION" --depth 1 https://github.com/Koenkk/zigbee2mqtt.git "$BUILDDIR" \
        || { BUILD_ERROR="Could not download zigbee2mqtt $ZIGBEE2MQTT_VERSION (internet connection?)"; return 1; }
    cd "$BUILDDIR" || { BUILD_ERROR="Could not enter $BUILDDIR"; return 1; }

    # NODE_VERSION is set in version.sh
    echo "<INFO> Downloading Node.js $NODE_VERSION ($NODE_ARCH)"
    NODE_TAR="node-$NODE_VERSION-linux-$NODE_ARCH.tar.xz"
    wget -q "https://nodejs.org/dist/$NODE_VERSION/$NODE_TAR" \
        || { BUILD_ERROR="Could not download Node.js $NODE_VERSION"; return 1; }
    mkdir -p "$BUILDDIR/node"
    tar -xf "$NODE_TAR" --strip-components=1 -C "$BUILDDIR/node" \
        || { BUILD_ERROR="Could not unpack Node.js $NODE_VERSION"; return 1; }
    rm -f "$NODE_TAR"
    export PATH=$BUILDDIR/node/bin:$PATH

    echo "<INFO> Node.js $(node --version)"
    npm install -g --silent "$(node -p "require('./package.json').packageManager")" \
        || { BUILD_ERROR="Could not install the package manager of zigbee2mqtt"; return 1; }
    echo "<INFO> Installing the dependencies of zigbee2mqtt (pnpm $(pnpm --version))"
    pnpm i --frozen-lockfile || { BUILD_ERROR="pnpm install failed"; return 1; }

    echo "<INFO> Building zigbee2mqtt"
    pnpm run build || { BUILD_ERROR="Build of zigbee2mqtt failed"; return 1; }

    # The data folder becomes a link to the plugin data folder
    rm -f -r "$BUILDDIR/data"
    return 0
}

if build_zigbee2mqtt; then
    cd /
    echo "<INFO> Replacing the zigbee2mqtt installation"
    rm -f -r "$INSTALLDIR.old"
    if [ -e "$INSTALLDIR" ]; then
        mv "$INSTALLDIR" "$INSTALLDIR.old"
    fi
    mv "$BUILDDIR" "$INSTALLDIR"
    rm -f -r "$INSTALLDIR.old"
    BUILD_OK=1
    echo "<OK> zigbee2mqtt $ZIGBEE2MQTT_VERSION installed"
else
    cd /
    rm -f -r "$BUILDDIR"
    if [ -x "$INSTALLDIR/node/bin/node" ] && [ -f "$INSTALLDIR/index.js" ]; then
        echo "<ERROR> $BUILD_ERROR. The previous zigbee2mqtt installation is kept and started again. Please install the plugin again later."
    else
        echo "<FAIL> $BUILD_ERROR. zigbee2mqtt is not installed."
        exit 2
    fi
fi

chown -R loxberry:loxberry $INSTALLDIR

echo "<INFO> Linking log to log folder"
ln -s -f -n $PLOG $INSTALLDIR/log

echo "<INFO> Updating data folder"
ln -s -f -n $PDATA $INSTALLDIR/data

# Fresh installation next to (or instead of) a predecessor plugin: take over
# its network so no device has to be paired again.
MIGRATED=0
for ORIGDIR in $PREDECESSORS; do
    ORIGSERVICE=$ORIGDIR
    ORIGDATA=$LBHOMEDIR/data/plugins/$ORIGDIR
    ORIGCONFIG=$LBHOMEDIR/config/plugins/$ORIGDIR
    if [ "$ISUPGRADE" -ne "0" ] || [ "$MIGRATED" -ne "0" ] || [ "$PDIR" = "$ORIGDIR" ]; then
        continue
    fi
    if [ ! -f "$ORIGDATA/configuration.yaml" ] || [ -f "$PDATA/configuration.yaml" ]; then
        continue
    fi
    echo "<INFO> Plugin $ORIGDIR found - taking over its Zigbee network"
    if systemctl is-active --quiet $ORIGSERVICE; then
        echo "<INFO> Stopping service $ORIGSERVICE (only one service may use the Zigbee adapter)"
        systemctl stop $ORIGSERVICE
    fi
    if systemctl is-enabled --quiet $ORIGSERVICE 2>/dev/null; then
        systemctl disable $ORIGSERVICE
    fi
    cp -a "$ORIGDATA/." "$PDATA/"
    for f in mqtt.json service.json; do
        if [ -f "$ORIGCONFIG/$f" ]; then
            cp -f "$ORIGCONFIG/$f" "$PCONFIG/$f"
        fi
    done
    # The MQTT gateway reads the subscriptions of every installed plugin. The
    # predecessor would otherwise keep <topic>/# (original) or its device
    # list registered until it is uninstalled.
    for f in mqtt_subscriptions.cfg mqtt_conversions.cfg mqtt_resetaftersend.cfg; do
        if [ -f "$ORIGCONFIG/$f" ]; then
            : > "$ORIGCONFIG/$f"
        fi
    done
    # An update of the predecessor would enable and start its service again.
    # Zigbee2Lox even fetches its updates from this repository and would
    # install Zigbee2MqttNG again every night.
    perl -I"$LBHOMEDIR/libs/perllib" -e '
        use LoxBerry::System::PluginDB;
        foreach my $md5 (LoxBerry::System::PluginDB->search(folder => $ARGV[0])) {
            my $plugin = LoxBerry::System::PluginDB->plugin(md5 => $md5);
            next if (!$plugin);
            $plugin->{autoupdate} = "0";
            $plugin->save();
        }' "$ORIGDIR" && echo "<INFO> Automatic updates of $ORIGDIR disabled" \
        || echo "<WARNING> Could not disable the automatic updates of $ORIGDIR. Please uninstall $ORIGDIR."

    if [ "$ORIGDIR" = "zigbee2lox" ]; then
        # The copied bridge extension of Zigbee2Lox must not be loaded next to
        # ours, its control file is rewritten by update-config.php below.
        rm -f "$PDATA/external_extensions/zigbee2lox.mjs" "$PDATA/zigbee2lox.json"
        for f in devices groups info haus; do
            if [ -f "$PDATA/zigbee2lox_$f.json" ]; then
                mv -f "$PDATA/zigbee2lox_$f.json" "$PDATA/zigbee2mqttng_$f.json"
            fi
        done
        # The haus/tuer topics belong to this plugin now - the uninstall of
        # Zigbee2Lox must not delete them.
        rm -f "$ORIGDATA/zigbee2lox_haus.json"
    fi

    MIGRATED=1
    echo "<WARNING> The plugin $ORIGDIR is still installed. Please uninstall it - an update of it would start its service again and both would fight for the Zigbee adapter."
done

# 4.1.1 (B3, Bauart F): second copies left by an earlier installation are
# never taken over by a fresh one - they are set aside
if [ "$ISUPGRADE" -eq "0" ]; then
    php $PBIN/zweitschrift.php beiseite "$PCONFIG"
fi

echo "<INFO> Refresh config"
php $PBIN/update-config.php
RC_UPDATE=$?
if [ "$RC_UPDATE" -eq "3" ]; then
    echo "<ERROR> A configuration file of Zigbee2MqttNG cannot be read and has no readable second copy. zigbee2mqtt is not started, so the Zigbee network is not lost. The settings page of the plugin says what to do."
elif [ "$RC_UPDATE" -ne "0" ]; then
    echo "<WARNING> update-config.php ended with code $RC_UPDATE - see the log Service of the plugin"
fi

chown loxberry:loxberry $PDATA/* -R
chown loxberry:loxberry $PCONFIG/* -R

# if we have a new installation we setup the encryption
# https://github.com/romanlum/LoxBerry-Plugin-Zigbee2Mqtt/issues/13
# A taken over network already has its key - generating a new one would cut off every device.
if [ "$ISUPGRADE" -eq "0" ] && [ "$MIGRATED" -eq "0" ]; then
    echo "<INFO> Fresh installation detected - Set encryption key"
    php $PBIN/setup-encryption.php
fi

# 4.1.1 (B4): the files with the broker password, the UI token and the
# network key are for loxberry only - also when they came from the archive
# (0644) or from a backup of 4.1.0
for f in "$PCONFIG/mqtt.json" "$PCONFIG/service.json" "$PDATA/configuration.yaml" "$PDATA/coordinator_backup.json" \
         "$PCONFIG"/*.kaputt "$PDATA"/*.kaputt "$LBHOMEDIR/config/plugins/$PDIR".backup.* "$LBHOMEDIR/config/plugins/$PDIR".alt.*; do
    if [ -f "$f" ]; then
        chown loxberry:loxberry "$f"
        chmod 600 "$f"
    fi
done

echo "<INFO> Updating service config"
ln -f -s $PCONFIG/zigbee2mqttng.service /etc/systemd/system/$SERVICE.service

# Enable auto-start of the service
systemctl daemon-reload
systemctl enable $SERVICE
systemctl start $SERVICE

if [ "$BUILD_OK" -ne "1" ]; then
    exit 1
fi
exit 0
