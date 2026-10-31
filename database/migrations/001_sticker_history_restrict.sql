-- Keep sticker records while chat history refers to them.
ALTER TABLE messages DROP FOREIGN KEY fk_messages_sticker;
ALTER TABLE messages
    ADD CONSTRAINT fk_messages_sticker
    FOREIGN KEY (sticker_id) REFERENCES stickers (id) ON DELETE RESTRICT;
