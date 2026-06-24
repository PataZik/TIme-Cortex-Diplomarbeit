#include <Wire.h>
#include <Adafruit_PN532.h>

#define SDA_PIN 5
#define SCL_PIN 4

Adafruit_PN532 nfc(-1, -1); // I2C

void setup() {
  Serial.begin(115200);
  delay(1500);

  Serial.println("START");
  Wire.begin(SDA_PIN, SCL_PIN);
  Wire.setClock(100000);

  Serial.println("PN532 begin...");
  nfc.begin();

  Serial.println("Read firmware...");
  uint32_t v = nfc.getFirmwareVersion();
  if (!v) {
    Serial.println("FAIL: PN532 nicht gefunden (I2C NACK / falscher Modus / Verkabelung).");
    while (1) delay(1000);
  }

  Serial.print("OK: PN532 FW = 0x");
  Serial.println(v, HEX);

  nfc.SAMConfig();
  Serial.println("SAMConfig OK. Halte eine Karte dran...");
}

void loop() {
  uint8_t uid[7];
  uint8_t uidLength;

  bool ok = nfc.readPassiveTargetID(PN532_MIFARE_ISO14443A, uid, &uidLength);

  if (ok) {
    Serial.print("UID: ");
    for (uint8_t i = 0; i < uidLength; i++) {
      if (uid[i] < 0x10) Serial.print("0");
      Serial.print(uid[i], HEX);
      if (i < uidLength - 1) Serial.print(" ");
    }
    Serial.println();
    delay(800);
  }
}