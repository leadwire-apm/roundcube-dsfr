## Start

```
docker-compose.exe -f docker-compose-fpm.yaml  up -d
```

## Connect  

GoTo : http://localhost:9009/

## Login 

ahmedamine.azouzi@leadwire.consulting  /   PXYbfccrg27

## Agenda - Initialize the calendar database tables

se connecter au conteneur : 

```
docker-compose -f docker-compose-fpm.yaml exec -it roundcubemail bash

bin/initdb.sh --dir=plugins/calendar/drivers/database/SQL
```

## Build css styles for the Elastic skin

se connecter au conteneur : 


```
docker-compose -f docker-compose-fpm.yaml exec -it roundcubemail bash

apt install -y node-less

lessc --relative-urls -x plugins/libkolab/skins/elastic/libkolab.less > plugins/libkolab/skins/elastic/libkolab.min.css
```

## Troubleshooting : problème d'affichage des messages 

Erreur : roundcubemail  | NOTICE: PHP message: PHP Fatal error:  Uncaught Error: Class 'Sabre\VObject\Property\Text' not found in  ... libcalendaring_vcalendar.php:1577

```
docker-compose -f docker-compose-fpm.yaml  exec -it roundcubemail  bash

composer require sabre/vobject
```

