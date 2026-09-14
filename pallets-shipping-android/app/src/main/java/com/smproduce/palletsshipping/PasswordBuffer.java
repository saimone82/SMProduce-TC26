package com.smproduce.palletsshipping;

/** Four ASCII digits only. Raw input stays outside the masked view hierarchy. */
final class PasswordBuffer {
    private final StringBuilder value = new StringBuilder();

    void append(String text) {
        if(text!=null && text.matches("[0-9]+") && value.length()+text.length()<=4)value.append(text);
    }
    void appendCodePoint(int codePoint) {
        if(codePoint>='0' && codePoint<='9')append(Character.toString((char)codePoint));
    }
    void erase() {
        if (value.length() == 0) return;
        int start = value.offsetByCodePoints(value.length(), -1);
        for (int i = start; i < value.length(); i++) value.setCharAt(i, '\0');
        value.setLength(start);
    }
    void clear() {
        for (int i = 0; i < value.length(); i++) value.setCharAt(i, '\0');
        value.setLength(0);
    }
    int count() { return value.codePointCount(0, value.length()); }
    String password() { return value.toString(); }
    String masked() {
        StringBuilder bullets = new StringBuilder();
        for (int i = 0; i < count(); i++) bullets.append('\u2022');
        return bullets.toString();
    }
}
