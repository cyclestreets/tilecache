#!/bin/bash

# Script to clear the cyclestreets cache.
# Run using sudo from any location
# Beware this uses rm -rf as sudo which has risks if it resolves to the wrong directory.

echo "#	Tilecache: clear the cyclestreets cache"

# Ensure this script is run as root
if [ "$(id -u)" != "0" ]; then
    echo "#	This script must be run as root." 1>&2
    exit 1
fi

# Bomb out if something goes wrong
set -e

# Get the script directory see: http://stackoverflow.com/a/246128/180733
# The multi-line method of geting the script directory is needed to enable the script to be called from elsewhere.
SOURCE="${BASH_SOURCE[0]}"
DIR="$( dirname "$SOURCE" )"
while [ -h "$SOURCE" ]
do
  SOURCE="$(readlink "$SOURCE")"
  [[ $SOURCE != /* ]] && SOURCE="$DIR/$SOURCE"
  DIR="$( cd -P "$( dirname "$SOURCE"  )" && pwd )"
done
DIR="$( cd -P "$( dirname "$SOURCE" )" && pwd )"
SCRIPTDIRECTORY=$DIR

# Check script is not empty
if [ -z "$SCRIPTDIRECTORY" ]; then
    echo "#	Target directory is empty" 1>&2
    exit 1
fi

# Check directory exists
if [ ! -d "$SCRIPTDIRECTORY" ]; then
    echo "#	Directory $SCRIPTDIRECTORY does not exist" 1>&2
    exit 1
fi

#	Clear the cyclestreets cache
rm -rf $SCRIPTDIRECTORY/cyclestreets

# Return true to indicate success
:

# End of file
