#!/bin/sh


# $1 - file name $2 URI prefix $3 final destination directory $4 optional check command


if [ -z "$1" ] || [ -z "$2" ] || [ -z "$3" ]; then
  echo Usage: $0 file_name URI_prefix destination_dir optional_check_command
  exit 124
fi

if [ -f /cache/etag/$1 ]; then
localetag=`cat  /cache/etag/$1`
else
#set invalid etag
localetag='unknown1234'
fi
remoteetag=`curl -I $2$1 | grep etag | cut -f 2 -d '"'`
if [ "$localetag" = "$remoteetag" ]; then
   echo local version of $1 remains current, skipping update
   exit 1
fi
   
echo downloading remote $1
wget --directory-prefix=/cache/tmp -c -nc $2$1
res=$?
if [ "$res" != "0" ]; then
   echo "the wget command failed with: $res"
   rm -f /cache/tmp/$1
   exit 2
fi
if [ ! -z "$4" ]; then
   eval $4 /cache/tmp/$1
   res=$?
   if [  ! "$res" = "0" ]; then
    echo Verification command $4 returned non-zero status $res, aborting update
    rm /cache/tmp/$1
    exit 3
   fi
fi
echo replacing $1 with new version
rm -f $3$1
mv /cache/tmp/$1 $3$1
echo $remoteetag > /cache/etag/$1


