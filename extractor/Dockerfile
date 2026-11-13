# cjenik-extractor: Java 17 servis koji svaki dan izvrsi SQL-ove nad AS400 (samo SELECT) i posalje cjenike portalu.
# Coolify: nova aplikacija iz istog repoa, Base Directory = /extractor, Build Pack = Dockerfile.
# Trajni volumen: /data (povijest cijena za "najnizu cijenu u 30 dana" - bez nje se povijest gubi pri svakom deployu!).
# SQL upiti se montiraju u /config/sql (ne nalaze se u repou jer otkrivaju strukturu baze).
FROM maven:3.9-eclipse-temurin-17 AS build
WORKDIR /src
COPY pom.xml .
RUN mvn -q -B dependency:go-offline
COPY src ./src
RUN mvn -q -B -DskipTests package

FROM eclipse-temurin:17-jre
RUN useradd --system --uid 10001 --home-dir /app extractor \
    && mkdir -p /config/sql /data \
    && chown -R extractor:extractor /data
COPY --from=build /src/target/cjenik-extractor.jar /app/cjenik-extractor.jar
USER extractor
ENV TZ=Europe/Zagreb \
    SQL_DIR=/config/sql \
    STATE_DIR=/data/state \
    OUT_DIR=/data/out \
    RUN_AT=07:00 \
    DEADLINE=07:45
VOLUME ["/data"]
ENTRYPOINT ["java", "-XX:MaxRAMPercentage=75", "-jar", "/app/cjenik-extractor.jar"]
