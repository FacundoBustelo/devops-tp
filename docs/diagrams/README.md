# UML y DER (E3–E5)

| Diagrama | Fuente | SVG |
|---|---|---|
| use-cases | [use-cases.puml](use-cases.puml) | [use-cases.svg](use-cases.svg) |
| login | [login.puml](login.puml) | [login.svg](login.svg) |
| search | [search.puml](search.puml) | [search.svg](search.svg) |
| get-gif | [get-gif.puml](get-gif.puml) | [get-gif.svg](get-gif.svg) |
| favorite | [favorite.puml](favorite.puml) | [favorite.svg](favorite.svg) |
| erd (proyecto) | [erd.puml](erd.puml) | [erd.svg](erd.svg) |
| erd-completo (proyecto, Laravel y Passport) | [erd-completo.puml](erd-completo.puml) | [erd-completo.svg](erd-completo.svg) |

Regeneración local: Java 17 o superior y PlantUML **1.2025.2**. No se usa servidor de renderizado; Smetana evita requerir Graphviz. Descargar el JAR fijado de Maven Central (solo descarga inicial), verificar SHA-256 y renderizar desde la raíz:

```bash
curl -fL https://repo.maven.apache.org/maven2/net/sourceforge/plantuml/plantuml/1.2025.2/plantuml-1.2025.2.jar -o /tmp/prex-plantuml-1.2025.2.jar
echo '1cd5997e353fc23bf9ddcf78d57ba846c1aa8a33dc4e3787872c8dec34fd35ce  /tmp/prex-plantuml-1.2025.2.jar' | sha256sum -c -
java -Djava.awt.headless=true -jar /tmp/prex-plantuml-1.2025.2.jar -charset UTF-8 -checkonly docs/diagrams/*.puml
java -Djava.awt.headless=true -jar /tmp/prex-plantuml-1.2025.2.jar -charset UTF-8 -tsvg docs/diagrams/*.puml
```

`erd` muestra el modelo del proyecto: `users`, `favorite_gifs` y `api_interactions`. Se incluye `users` por su relación con favoritos y auditoría, aunque su migración base provenga de Laravel. `erd-completo` conserva las 16 tablas de las migraciones versionadas, incluida `migrations` creada por Laravel. Las cinco tablas oficiales de Passport 13 no crean FK: columnas foreignId/foreignUuid se representan con líneas punteadas. Las únicas FK físicas son favorite_gifs.user_id (RESTRICT) y api_interactions.user_id (SET NULL). Las relaciones polimórficas y por email son lógicas. Las marcas NULL incluyen timestamps del framework. Comparación externa exacta: gif_id usa utf8mb4_0900_bin, sin normalizar.

Los diagramas muestran puertos y adaptadores juntos donde la delegación no añade comportamiento. Auditoría fuera de favoritos conserva respuesta si falla su propia escritura; dentro de favoritos el fallo revierte la creación. El middleware omite captura duplicada solo tras la marca posterior a commit. Las rutas OAuth permanecen deshabilitadas aunque sus tablas oficiales estén presentes.
