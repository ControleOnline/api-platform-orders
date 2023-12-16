# orders


`composer require controleonline/orders:dev-master`



Create a new fila on controllers:
config\routes\controllers\orders.yaml

```yaml
controllers:
    resource: ../../vendor/controleonline/orders/src/Controller/
    type: annotation      
```

Add to entities:
nelsys-api\config\packages\doctrine.yaml
```yaml
doctrine:
    orm:
        mappings:
           orders:
                is_bundle: false
                type: annotation
                dir: "%kernel.project_dir%/vendor/controleonline/orders/src/Entity"
                prefix: 'ControleOnline\Entity'
                alias: ControleOnline                             
```          


Add this line on your routes:
config\packages\api_platform.yaml
```yaml          
mapping   :
    paths: ['%kernel.project_dir%/src/Entity','%kernel.project_dir%/src/Resource',"%kernel.project_dir%/vendor/controleonline/orders/src/Entity"]        
```          
