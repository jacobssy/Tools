#!/usr/bin/env python
# -*- coding: utf-8 -*-
# @Time    : 2017/3/22 20:04
# @Author  : sunsiyuan
# @Email    :710857385@qq.com 
# @File    : CountLines.py
# @Software: PyCharm Community Edition

import  os
import  sys
import  ConfigParser as config

'''
@:func judeLine:判断某一行所属的种类
@:param ( line:读取文件的一行内容
          notes:文件种类所含的注释标志
        )
@:return (1.注释行
          2.空白行
          3.代码行
          )
'''
def judeLine(line,notes):
    line = line.lstrip()
    if len(line)>0:
        if (line[0] in notes):
            return 1
        else:
            return 3
    else:

            return 2

'''
@:func countFile :统计文件的各类代码行数
@：param (path :文件路径
          conf:配置文件
          type:文件后缀名
         )
@：return (
         count_total:总行数
         count_blank：空白行
         count_note：注释行
         count_code：代码行
         )
'''
def countFile(path,conf,type):
    count_note = 0
    count_blank = 0
    count_code = 0
    count_total = 0
    f=open(path)
    lines=f.readlines()

    notes = []
    options = cf.options(type)
    for i in range(len(options)):
        notes.append(cf.get(type, options[i]))
    for line in lines:
        index=judeLine(line,notes)
        if (index==1):
            count_note += 1
        if (index==2):
            count_blank += 1
        if (index==3):
            count_code += 1
        count_total=count_blank+count_code+count_note
    print '%s 文件的代码情况如下：' %path
    print '代码行为：%s' %count_code
    print '注释行为：%s' %count_note
    print  '空白行为:%s' %count_blank
    print  '总共代码行为:%s' %count_total
    return count_total,count_blank,count_note,count_code


'''
@:func 主函数,打印出工程总体情况
@：param
@:return
'''
if __name__ == '__main__':
    count_note = 0
    count_blank = 0
    count_code = 0
    count_total = 0
    cf = config.ConfigParser()
    print '正在读取配置文件.........'
    cf.read('./config/CountLines.conf')

    print'工程代码统计CountLines.py脚本正在运行  你的工程目录为：%s' %(sys.argv[len(sys.argv)-1])

    #得到目录下所有的文件（包括子目录下的文件）
    for root,dirs,files in os.walk(sys.argv[len(sys.argv)-1]):
        for file in files:
            if file.find('.')>0:
                if file.split('.')[1] in cf.sections():
                    total,blank,note,code=countFile(os.path.join(root,file),cf,file.split('.')[1])
                    count_code += code
                    count_note += note
                    count_blank+= blank
                    count_total+= total
                else:
                    print '文件%s不在统计的种类里...此文件有可能是隐藏文件或者二进制可执行文件' %file
            else:
                print '文件%s没有文件后缀名...此文件有可能是隐藏文件或者二进制可执行文件' %file
    print '******************************************************************************************'
    print '\n**  其中空白行总共为: %s ' % count_blank
    print '\n**  其中注释行总共为: %s ' % count_note
    print '\n**  其中代码行总共为: %s ' % count_code
    print '\n**  此工程代码行总共为: %s' % count_total


